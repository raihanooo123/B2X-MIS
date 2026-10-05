<?php

namespace App\Domain\Cms;

use App\Models\SystemConfiguration;

/**
 * 05.11 §4.4 — may search engines index this site? Only in production, and
 * only once the business has switched `seo.indexing_enabled` on (Storefront
 * settings; default off until the legal review, 05.15 Q1, has passed).
 * Otherwise every response says `noindex, nofollow`, robots.txt disallows
 * everything and there is no sitemap.
 */
final class SearchIndexing
{
    public const KEY = 'seo.indexing_enabled';

    public static function enabled(): bool
    {
        return app()->environment('production') && self::switchedOn();
    }

    /** The stored switch alone, whatever the environment (the settings form). */
    public static function switchedOn(): bool
    {
        $value = SystemConfiguration::query()
            ->where('config_key', self::KEY)
            ->where('scope', 'global')
            ->value('value_int');

        return $value !== null && (int) $value === 1;
    }
}
