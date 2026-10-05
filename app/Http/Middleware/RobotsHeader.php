<?php

namespace App\Http\Middleware;

use App\Domain\Cms\SearchIndexing;
use App\Domain\Seo\SeoHead;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 05.11 §4.2 — `X-Robots-Tag` on every web response, **deny by default**.
 *
 * A page that attached a SeoHead says what it may be (`index, follow`,
 * `noindex, follow` for search and filtered listings). Everything else —
 * cart, checkout, account, orders, sign-in, order pad, warehouse, error
 * pages — is `noindex, nofollow` without having to be listed, so a new
 * private page can never be indexed by omission. While indexing is off
 * (SearchIndexing), every response is `noindex, nofollow`.
 */
final class RobotsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->has('X-Robots-Tag')) {
            return $response;
        }

        $value = $request->attributes->get(SeoHead::ROBOTS_ATTRIBUTE);
        if (! SearchIndexing::enabled() || ! is_string($value)) {
            $value = 'noindex, nofollow';
        }
        // An indexable page needs no header: absence means "index, follow".
        if ($value !== 'index, follow') {
            $response->headers->set('X-Robots-Tag', $value);
        }

        return $response;
    }
}
