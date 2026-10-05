<?php

namespace App\Domain\Seo;

use App\Domain\Accounts\TermsKind;
use App\Domain\Cms\CurrentPages;
use App\Models\TermsVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 05.11 §6.1 — `/sitemap.xml`: the home page, active categories, active
 * published products with at least one active SKU, the legal and help
 * pages in force, and the terms of sale when a version is in force.
 *
 * Products are read by keyset over `products_active_published_idx`
 * (02 §5.4), never OFFSET. Over 50,000 URLs the sitemap becomes an index
 * of `/sitemaps/products-{n}.xml` files of 50,000 products each, plus
 * `/sitemaps/pages.xml`. Cached for an hour; SitemapCacheObserver drops
 * the cache when a product, category or page changes.
 */
final class Sitemap
{
    public const PER_FILE = 50000;

    private const CACHE_PREFIX = 'seo:sitemap:';

    private const CACHE_SECONDS = 3600;

    public static function forget(): void
    {
        Cache::forget(self::CACHE_PREFIX.'root');
        Cache::forget(self::CACHE_PREFIX.'pages');
        $files = (int) Cache::get(self::CACHE_PREFIX.'files', 0);
        for ($n = 1; $n <= max(1, $files); $n++) {
            Cache::forget(self::CACHE_PREFIX.'products-'.$n);
        }
        Cache::forget(self::CACHE_PREFIX.'files');
    }

    /** `/sitemap.xml`: a plain sitemap, or an index once there are too many products. */
    public function root(): string
    {
        return Cache::remember(self::CACHE_PREFIX.'root', self::CACHE_SECONDS, function (): string {
            $files = $this->productFileCount();
            Cache::put(self::CACHE_PREFIX.'files', $files, self::CACHE_SECONDS);

            if ($files <= 1) {
                return $this->urlset([...$this->pageEntries(), ...$this->productEntries(null)]);
            }

            $now = CarbonImmutable::now()->toAtomString();
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $xml .= '  <sitemap><loc>'.self::e(SeoHead::url('/sitemaps/pages.xml')).'</loc><lastmod>'.$now.'</lastmod></sitemap>'."\n";
            for ($n = 1; $n <= $files; $n++) {
                $xml .= '  <sitemap><loc>'.self::e(SeoHead::url("/sitemaps/products-{$n}.xml")).'</loc><lastmod>'.$now.'</lastmod></sitemap>'."\n";
            }

            return $xml.'</sitemapindex>'."\n";
        });
    }

    public function pages(): string
    {
        return Cache::remember(self::CACHE_PREFIX.'pages', self::CACHE_SECONDS, fn (): string => $this->urlset($this->pageEntries()));
    }

    /** @return string|null null when there is no such file */
    public function products(int $n): ?string
    {
        if ($n < 1 || $n > $this->productFileCount()) {
            return null;
        }

        return Cache::remember(self::CACHE_PREFIX.'products-'.$n, self::CACHE_SECONDS, fn (): string => $this->urlset($this->productEntries($n)));
    }

    /** @return list<array{loc: string, lastmod: string|null}> */
    private function pageEntries(): array
    {
        $entries = [['loc' => SeoHead::url('/'), 'lastmod' => null]];

        DB::table('categories')->where('status', 'active')->orderBy('path')->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function ($c) use (&$entries): void {
                $entries[] = ['loc' => SeoHead::url('/c/'.$c->slug), 'lastmod' => self::date($c->updated_at)];
            });

        foreach (CurrentPages::links() as $link) {
            $entries[] = ['loc' => SeoHead::url($link['path']), 'lastmod' => null];
        }

        $terms = TermsVersion::current(TermsKind::Sale);
        if ($terms !== null) {
            $entries[] = ['loc' => SeoHead::url('/terms'), 'lastmod' => $terms->effective_from->toAtomString()];
        }

        return $entries;
    }

    /**
     * @param  int|null  $file  1-based file number, or null for every product
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function productEntries(?int $file): array
    {
        $entries = [];
        $skip = $file === null ? 0 : ($file - 1) * self::PER_FILE;
        $limit = $file === null ? PHP_INT_MAX : self::PER_FILE;
        $after = null;
        $seen = 0;

        // Keyset over (published_at DESC, id DESC); the first $skip rows are
        // walked past in chunks, never skipped with OFFSET.
        while (count($entries) < $limit) {
            $chunk = $this->productQuery()
                ->when($after !== null, fn ($q) => $q->whereRaw('(p.published_at, p.id) < (?, ?)', $after))
                ->limit(1000)
                ->get(['p.id', 'p.slug', 'p.published_at', 'p.updated_at']);
            if ($chunk->isEmpty()) {
                break;
            }
            foreach ($chunk as $p) {
                $after = [$p->published_at, $p->id];
                if ($seen++ < $skip) {
                    continue;
                }
                $entries[] = ['loc' => SeoHead::url('/p/'.$p->slug), 'lastmod' => self::date($p->updated_at)];
                if (count($entries) >= $limit) {
                    break;
                }
            }
        }

        return $entries;
    }

    private function productFileCount(): int
    {
        return max(1, intdiv($this->productQuery()->count() + self::PER_FILE - 1, self::PER_FILE));
    }

    private function productQuery(): Builder
    {
        return DB::table('products as p')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.published_at')
            ->where('p.published_at', '<=', now())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('skus as s')
                ->whereColumn('s.product_id', 'p.id')->where('s.status', 'active')->whereNull('s.deleted_at'))
            ->orderByDesc('p.published_at')
            ->orderByDesc('p.id');
    }

    /** @param  list<array{loc: string, lastmod: string|null}>  $entries */
    private function urlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $entry) {
            $xml .= '  <url><loc>'.self::e($entry['loc']).'</loc>'.($entry['lastmod'] === null ? '' : '<lastmod>'.$entry['lastmod'].'</lastmod>').'</url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toAtomString();
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
