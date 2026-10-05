<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Cms\SearchIndexing;
use App\Domain\Seo\SeoHead;
use App\Domain\Seo\Sitemap;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * 05.11 §6 — `/sitemap.xml` (and its parts once it is an index) and
 * `robots.txt`. Served by routes rather than static files because both
 * depend on whether indexing is on (SearchIndexing): off, there is no
 * sitemap and robots.txt disallows everything.
 */
class SeoController extends Controller
{
    /** Private areas a crawler has no business fetching (05.11 §6.2). */
    private const DISALLOW = ['/admin', '/cart', '/checkout', '/account', '/orders/', '/order-pad', '/warehouse', '/api/'];

    public function __construct(
        private readonly Sitemap $sitemap = new Sitemap,
    ) {}

    public function sitemap(): Response
    {
        abort_unless(SearchIndexing::enabled(), 404);

        return $this->xml($this->sitemap->root());
    }

    public function sitemapPages(): Response
    {
        abort_unless(SearchIndexing::enabled(), 404);

        return $this->xml($this->sitemap->pages());
    }

    public function sitemapProducts(int $n): Response
    {
        abort_unless(SearchIndexing::enabled(), 404);
        $xml = $this->sitemap->products($n);
        abort_if($xml === null, 404);

        return $this->xml($xml);
    }

    public function robots(): Response
    {
        if (! SearchIndexing::enabled()) {
            return $this->text("User-agent: *\nDisallow: /\n");
        }

        $lines = ['User-agent: *'];
        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = 'Sitemap: '.SeoHead::url('/sitemap.xml');

        return $this->text(implode("\n", $lines)."\n");
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
