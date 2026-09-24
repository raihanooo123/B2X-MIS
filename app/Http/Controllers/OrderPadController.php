<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\OrderPadCatalogue;
use App\Domain\Ordering\CartOwnerResolver;
use App\Domain\Ordering\OrderPadTotalsContext;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Doc 05.1 — the order pad page. The rows (one keyset page of active
 * SKUs) arrive as Inertia props; prices, stock and the cart are fetched
 * client-side through lib/api/orderPad.ts for those SKU ids, so nothing
 * here duplicates what TanStack Query fetches.
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
        private readonly CartOwnerResolver $ownerResolver = new CartOwnerResolver,
    ) {}

    public function index(Request $request): Response
    {
        $after = $request->query('after');
        $user = $request->user();
        $companyId = $user instanceof User ? $this->ownerResolver->forUser($user)->companyId : null;

        return Inertia::render('OrderPad/Index', [
            'catalogue' => $this->catalogue->page(is_string($after) ? $after : null),
            'page_size' => OrderPadCatalogue::PAGE_SIZE,
            'totals_context' => $this->totalsContext->for($companyId),
        ]);
    }
}
