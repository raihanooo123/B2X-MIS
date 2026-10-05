<?php

namespace App\Domain\Cms;

use App\Domain\Accounts\TermsKind;
use App\Models\CmsPageVersion;
use App\Models\TermsVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 05.11 §2.2, §3 — the version of each page in force: the latest
 * `effective_from <= now()`, served by `cms_page_versions_effective_uq`.
 *
 * One query for all five pages, plus whether terms of sale are in force
 * (the footer's `/terms` link), cached until the next scheduled page or
 * terms version takes effect (at most an hour), and dropped on publish
 * (CmsPublisher, TermsPublisher). The footer on every storefront page
 * reads only this cache: no query per request while it is warm.
 */
final class CurrentPages
{
    private const CACHE_KEY = 'cms:current-pages';

    private const MAX_TTL_SECONDS = 3600;

    public static function version(PageKey $key): ?CmsPageVersion
    {
        $id = self::currentIds()[$key->value] ?? null;

        return $id === null ? null : CmsPageVersion::query()->find($id);
    }

    /**
     * Pages with a version in force, in PageKey order — the footer links.
     *
     * @return list<array{key: string, label: string, path: string}>
     */
    public static function links(): array
    {
        $ids = self::currentIds();
        $links = [];
        foreach (PageKey::cases() as $key) {
            if (isset($ids[$key->value])) {
                $links[] = ['key' => $key->value, 'label' => $key->label(), 'path' => $key->path()];
            }
        }

        return $links;
    }

    /** Whether a terms of sale version is in force (the footer's `/terms` link). */
    public static function saleTermsInForce(): bool
    {
        return self::cached()['terms'];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, int> page key => version id in force */
    private static function currentIds(): array
    {
        return self::cached()['ids'];
    }

    /** @return array{ids: array<string, int>, terms: bool} */
    private static function cached(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && is_array($cached['ids'] ?? null) && is_bool($cached['terms'] ?? null)) {
            /** @var array{ids: array<string, int>, terms: bool} $cached */
            return $cached;
        }

        return self::load();
    }

    /** @return array{ids: array<string, int>, terms: bool} */
    private static function load(): array
    {
        $now = CarbonImmutable::now();

        $ids = [];
        DB::table('cms_pages as p')
            ->joinLateral(
                DB::table('cms_page_versions as v')
                    ->whereColumn('v.cms_page_id', 'p.id')
                    ->where('v.effective_from', '<=', $now)
                    ->orderByDesc('v.effective_from')
                    ->limit(1)
                    ->select('v.id'),
                'current',
            )
            ->get(['p.page_key', 'current.id'])
            ->each(function ($row) use (&$ids): void {
                $ids[(string) $row->page_key] = (int) $row->id;
            });

        $terms = TermsVersion::current(TermsKind::Sale) !== null;

        // Expire when the next scheduled page or terms of sale version takes effect.
        $ttl = self::MAX_TTL_SECONDS;
        foreach ([
            DB::table('cms_page_versions')->where('effective_from', '>', $now)->min('effective_from'),
            DB::table('terms_versions')->where('kind', TermsKind::Sale->value)->where('effective_from', '>', $now)->min('effective_from'),
        ] as $next) {
            if ($next !== null) {
                $ttl = max(1, min($ttl, (int) $now->diffInSeconds(CarbonImmutable::parse((string) $next), true)));
            }
        }

        $value = ['ids' => $ids, 'terms' => $terms];
        Cache::put(self::CACHE_KEY, $value, $ttl);

        return $value;
    }
}
