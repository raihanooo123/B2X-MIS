<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Storefront\ProductDetail;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Support\CartContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.15 §5.3 — the product page, `/p/{slug}`. Adding to the basket goes
 * through the existing cart API (06 §8), which guests may use too.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductDetail $details = new ProductDetail,
        private readonly StorefrontShell $shell = new StorefrontShell,
        private readonly CartContext $cartContext = new CartContext,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $owner = $this->cartContext->owner($request, createGuestToken: false);
        $product = $this->details->bySlug($slug, $owner?->companyId);
        abort_if($product === null, 404);

        return Inertia::render('Storefront/Product', [
            'shell' => fn () => $this->shell->props($owner),
            'product' => $product,
        ]);
    }
}
