<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Seo\SeoHead;
use App\Domain\Seo\StructuredData;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\ProductCards;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Support\CartContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.15 §5.1 — the storefront home page: the brand's hero with search,
 * the top-level categories, and a grid of featured then newest products,
 * priced for whoever is looking (a trade user sees their own prices, 03
 * §4.2; everyone else `base`).
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly ProductCards $cards = new ProductCards,
        private readonly StorefrontShell $shell = new StorefrontShell,
        private readonly CartContext $cartContext = new CartContext,
    ) {}

    public function __invoke(Request $request): Response
    {
        $owner = $this->cartContext->owner($request, createGuestToken: false);

        $brand = Branding::current();
        $seo = new SeoHead(
            title: $brand->tagline === null ? $brand->name : $brand->name.' — '.$brand->tagline,
            description: SeoHead::describe($brand->tagline, "Shop online with {$brand->name}."),
            canonical: SeoHead::url('/'),
            ogImage: is_string($logo = $brand->toArray()['logo_url'] ?? null) ? (str_starts_with($logo, 'http') ? $logo : SeoHead::url($logo)) : null,
            jsonLd: [StructuredData::organization($brand)],
        );

        return $seo->attach(Inertia::render('Storefront/Home', [
            'shell' => $this->shell->props($owner),
            'products' => $this->cards->homepage($owner?->companyId),
            'departments' => $this->shell->departments(),
        ]));
    }
}
