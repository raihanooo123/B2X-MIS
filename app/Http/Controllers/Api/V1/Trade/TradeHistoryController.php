<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Billing\TradeDocuments;
use App\Domain\Ordering\TradeOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\InvoiceListRequest;
use App\Http\Requests\Trade\OrderHistoryRequest;
use App\Http\Support\KeysetCursor;
use App\Http\Support\TradeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 05.17 §4 / 06 §18 — GET /api/v1/trade/orders, /orders/{id} and
 * /invoices: the same lists as the trade pages, as 06 list DTOs
 * (data, next_cursor, has_more; 50 by default, 100 at most). The cursor is
 * bound to user, company, filters and sort. Reads only.
 */
class TradeHistoryController extends Controller
{
    public function __construct(
        private readonly TradeOrders $orders,
        private readonly TradeDocuments $documents,
    ) {}

    public function orders(OrderHistoryRequest $request): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);

        $filters = $request->filters();
        $after = KeysetCursor::decode($request->cursor(), 'api.trade.orders', $user->id, $company->id, $filters);
        $page = $this->orders->page($company->id, $filters, $after, $request->perPage());

        return $this->list($page, fn (array $next) => KeysetCursor::encode('api.trade.orders', $user->id, $company->id, $filters, $next));
    }

    public function order(Request $request, string $id): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);
        $order = TradeOrders::find($company, $id) ?? abort(404);

        return response()->json(['data' => $this->orders->detail($order, $user, Gate::allows('viewFinancialDocuments', $company))]);
    }

    public function invoices(InvoiceListRequest $request): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);

        $filters = $request->filters();
        $after = KeysetCursor::decode($request->cursor(), 'api.trade.invoices', $user->id, $company->id, $filters);
        $page = $this->documents->invoices($company->id, $filters, $after, $request->perPage());

        return $this->list($page, fn (array $next) => KeysetCursor::encode('api.trade.invoices', $user->id, $company->id, $filters, $next));
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}  $page
     * @param  callable(array<string, int|string>): string  $encode
     */
    private function list(array $page, callable $encode): JsonResponse
    {
        return response()->json([
            'data' => $page['rows'],
            'next_cursor' => $page['next'] === null ? null : $encode($page['next']),
            'has_more' => $page['next'] !== null,
        ]);
    }
}
