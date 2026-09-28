<?php

namespace App\Http\Controllers\Storefront;

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

        return Inertia::render('Storefront/Home', [
            'shell' => $this->shell->props($owner),
            'products' => $this->cards->homepage($owner?->companyId),
        ]);
    }
}
