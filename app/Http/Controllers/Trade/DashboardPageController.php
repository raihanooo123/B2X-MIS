<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Ordering\TradeDashboard;
use App\Http\Controllers\Controller;
use App\Http\Support\TradeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.17 §4 — GET /trade, the trade dashboard, for every member of the
 * acting company. Money cards are sent to owners and approvers only.
 */
class DashboardPageController extends Controller
{
    public function __construct(private readonly TradeDashboard $dashboard = new TradeDashboard) {}

    public function __invoke(Request $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);

        return Inertia::render('Trade/Dashboard', [
            'dashboard' => $this->dashboard->build(
                $company,
                Gate::allows('viewFinancialDocuments', $company),
                Gate::allows('viewApprovals', $company),
            ),
        ]);
    }
}
