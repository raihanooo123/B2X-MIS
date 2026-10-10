<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Credit\CreditOverview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\CursorRequest;
use App\Http\Support\KeysetCursor;
use App\Http\Support\TradeContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.2 §18.3 — account credit (summary, on-account state, overdue alert,
 * ageing, outstanding invoices) and the balance ledger. Owners and
 * approvers of the acting company; buyers see only what checkout shows.
 */
class CreditPageController extends Controller
{
    public function __construct(private readonly CreditOverview $overview = new CreditOverview) {}

    public function credit(CursorRequest $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewCredit', $company);

        $after = KeysetCursor::decode($request->cursor(), 'credit.invoices', $user->id, $company->id, []);
        $page = $this->overview->invoices($company->id, $after);

        return Inertia::render('Trade/Account/Credit', [
            'company' => TradeContext::companyProps($company),
            'summary' => fn () => $this->overview->summary($company),
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode('credit.invoices', $user->id, $company->id, [], $page['next']),
        ]);
    }

    public function balance(CursorRequest $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewCredit', $company);

        $after = KeysetCursor::decode($request->cursor(), 'credit.balance', $user->id, $company->id, []);
        $page = $this->overview->movements($company->id, $after);

        return Inertia::render('Trade/Account/Balance', [
            'company' => TradeContext::companyProps($company),
            'balance_minor' => fn () => (int) $company->account_balance_minor,
            'payouts' => fn () => $this->overview->payouts($company->id),
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode('credit.balance', $user->id, $company->id, [], $page['next']),
        ]);
    }
}
