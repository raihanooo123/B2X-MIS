<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\OrderPadCatalogue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Doc 05.1 — the order pad page. The rows (one keyset page of active
 * SKUs) arrive as Inertia props; prices, stock and the cart are fetched
 * client-side through lib/api/orderPad.ts for those SKU ids, so nothing
 * here duplicates what TanStack Query fetches.
 *
 * Public: guests browse and have carts too (02 §14.3), and the storefront
 * login flow (05.13) is not written yet.
 */
class OrderPadController extends Controller
{
    public function __construct(
        private readonly OrderPadCatalogue $catalogue = new OrderPadCatalogue,
    ) {}

    public function index(Request $request): Response
    {
        $after = $request->query('after');

        return Inertia::render('OrderPad/Index', [
            'catalogue' => $this->catalogue->page(is_string($after) ? $after : null),
            'page_size' => OrderPadCatalogue::PAGE_SIZE,
        ]);
    }
}
