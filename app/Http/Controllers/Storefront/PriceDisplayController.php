<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PriceDisplayRequest;
use App\Http\Support\PriceDisplay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * 05.15 §4.1 — the header's *Inc VAT / Ex VAT* switch. Only guests and
 * public customers may switch (PriceDisplayRequest authorises); the
 * choice is a first-party cookie for a year.
 */
class PriceDisplayController extends Controller
{
    public function __invoke(PriceDisplayRequest $request): RedirectResponse
    {
        Cookie::queue(Cookie::make(PriceDisplay::COOKIE, $request->mode(), PriceDisplay::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'lax'));

        return back();
    }
}
