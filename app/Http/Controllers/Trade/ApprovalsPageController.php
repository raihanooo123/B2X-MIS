<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Credit\ApprovalQueue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\ApprovalQueueRequest;
use App\Http\Support\KeysetCursor;
use App\Http\Support\TradeContext;
use App\Models\OrderApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.2 §18.3 — the approval queue and request detail, for the acting
 * company's owners and approvers. Read only: decisions are POSTs to
 * /api/v1/approvals (Api\V1\Trade\ApprovalController).
 */
class ApprovalsPageController extends Controller
{
    private const LIST = 'approvals';

    public function __construct(private readonly ApprovalQueue $queue = new ApprovalQueue) {}

    public function index(ApprovalQueueRequest $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewApprovals', $company);

        $filters = $request->filters();
        $after = KeysetCursor::decode($request->cursor(), self::LIST, $user->id, $company->id, $filters);
        $page = $this->queue->page($company->id, $user, $filters, $after);

        return Inertia::render('Trade/Approvals/Index', [
            'company' => TradeContext::companyProps($company),
            'filters' => $filters,
            'buyers' => fn () => $this->queue->buyers($company->id),
            'pending_count' => fn () => $this->queue->pendingCount($company->id),
            // 05.16 §3: "Load more" appends on a partial reload.
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode(self::LIST, $user->id, $company->id, $filters, $page['next']),
        ]);
    }

    public function show(Request $request, string $approval): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        $record = OrderApprovalRequest::query()->where('public_id', $approval)->where('company_id', $company->id)->firstOrFail();
        Gate::authorize('view', $record);

        return Inertia::render('Trade/Approvals/Show', [
            'approval' => $this->queue->detail($record, $user),
        ]);
    }
}
