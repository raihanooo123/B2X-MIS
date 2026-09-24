<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\OrderPadCatalogue;
use App\Domain\Ordering\OrderPadTotalsContext;
use App\Http\Requests\Web\OrderPadRequest;
use App\Http\Support\CartContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Doc 05.1 — the order pad page. The rows (one keyset page of active
 * SKUs) arrive as Inertia props; prices, stock and the cart are fetched
 * client-side through lib/api/orderPad.ts for those SKU ids, so nothing
 * here duplicates what TanStack Query fetches.
 *
 * `filters` echoes the normalised search/category/brand/in-stock state
 * back (OrderPadRequest); `facets` lists the category and brand options.
 * The page reloads only `catalogue` and `filters` when a filter changes.
 *
 * `totals_context` is the per-order half of local recompute (spend
 * breaks, carriage-paid threshold — see OrderPadTotalsContext), resolved
 * for the same company checkout preview prices against.
 *
 * Public: guests browse and have carts too (02 §14.3), and the storefront
 * login flow (05.13) is not written yet.
 */
class OrderPadController extends Controller
{
    public function __construct(
        private readonly OrderPadCatalogue $catalogue = new OrderPadCatalogue,
        private readonly OrderPadTotalsContext $totalsContext = new OrderPadTotalsContext,
        private readonly CartContext $cartContext = new CartContext,
    ) {}

    public function index(OrderPadRequest $request): Response
    {
        $filters = $request->filters();
        // The company the user is acting for (05.13 §6.3) — the same one
        // checkout preview prices against.
        $companyId = $this->cartContext->owner($request, createGuestToken: false)?->companyId;

        return Inertia::render('OrderPad/Index', [
            'catalogue' => $this->catalogue->page($request->cursor(), $filters),
            'filters' => $filters->toArray(),
            'page_size' => OrderPadCatalogue::PAGE_SIZE,
            'facets' => fn () => $this->catalogue->facets(),
            'totals_context' => fn () => $this->totalsContext->for($companyId),
        ]);
    }
}
