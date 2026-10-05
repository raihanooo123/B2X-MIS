<?php

namespace App\Observers;

use App\Domain\Seo\Sitemap;

/**
 * 05.11 §6.1 — drops the cached sitemap when something it lists changes:
 * a product or category saved or deleted, or a page version published.
 * After commit, so a rolled-back change never empties the cache for
 * nothing and a reader never caches the uncommitted state.
 */
final class SitemapCacheObserver
{
    public bool $afterCommit = true;

    public function saved(): void
    {
        Sitemap::forget();
    }

    public function deleted(): void
    {
        Sitemap::forget();
    }
}
