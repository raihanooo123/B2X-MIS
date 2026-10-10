<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Ordering\TradeOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\OrderHistoryRequest;
use App\Http\Support\KeysetCursor;
use App\Http\Support\TradeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.17 §4 — company order history and order detail, for every member of
 * the acting company. Another company's order is a 404, whatever its id.
 */
class OrdersPageController extends Controller
{
    private const LIST = 'trade.orders';

    public function __construct(private readonly TradeOrders $orders = new TradeOrders) {}

    public function index(OrderHistoryRequest $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);

        $filters = $request->filters();
        $after = KeysetCursor::decode($request->cursor(), self::LIST, $user->id, $company->id, $filters);
        $page = $this->orders->page($company->id, $filters, $after);

        return Inertia::render('Trade/Orders/Index', [
            'company' => TradeContext::companyProps($company),
            'filters' => $filters,
            'drafts' => fn () => $this->orders->drafts($company->id),
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode(self::LIST, $user->id, $company->id, $filters, $page['next']),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);
        $record = TradeOrders::find($company, $order) ?? abort(404);

        return Inertia::render('Trade/Orders/Show', [
            'order' => $this->orders->detail($record, $user, Gate::allows('viewFinancialDocuments', $company)),
        ]);
    }
}
