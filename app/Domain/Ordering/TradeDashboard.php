<?php

namespace App\Domain\Ordering;

use App\Domain\Credit\ApprovalQueue;
use App\Domain\Credit\CreditGate;
use App\Domain\Credit\CreditOverview;
use App\Models\Company;
use App\Models\Order;

/**
 * 05.17 §2 — the trade dashboard: the account's status and terms, what is
 * waiting (approval, payment, in progress), recent orders, and — for
 * owners and approvers only — credit, overdue debt and spendable balance.
 * Buyers and viewers never receive account-wide money figures.
 */
final class TradeDashboard
{
    private const TERMS = [
        'prepay' => 'Pay in advance',
        'net7' => '7 days',
        'net14' => '14 days',
        'net30' => '30 days',
        'net60' => '60 days',
    ];

    public function __construct(
        private readonly TradeOrders $orders = new TradeOrders,
        private readonly CreditOverview $credit = new CreditOverview,
        private readonly CreditGate $gate = new CreditGate,
        private readonly ApprovalQueue $approvals = new ApprovalQueue,
    ) {}

    /** @return array<string, mixed> */
    public function build(Company $company, bool $finance, bool $approver): array
    {
        $refusal = $this->gate->companyRefusal($company, 'on_account');
        // One grouped count over orders_open_status_idx's statuses.
        $counts = Order::query()->where('company_id', $company->id)
            ->whereIn('status', ['awaiting_approval', 'pending_payment', 'confirmed', 'picking', 'part_dispatched'])
            ->groupBy('status')->selectRaw('status, count(*) AS n')->pluck('n', 'status');

        $summary = $finance ? $this->credit->summary($company) : null;

        return [
            'account' => [
                'name' => $company->name,
                'account_code' => (string) $company->account_code,
                'status' => $company->status,
                'payment_terms' => $company->payment_terms,
                'payment_terms_label' => self::TERMS[$company->payment_terms] ?? $company->payment_terms,
                'on_account' => ['allowed' => $refusal === null, 'message' => $refusal?->getMessage()],
            ],
            'waiting' => [
                'awaiting_approval' => (int) ($counts['awaiting_approval'] ?? 0),
                'pending_payment' => (int) ($counts['pending_payment'] ?? 0),
                'in_progress' => (int) ($counts['confirmed'] ?? 0) + (int) ($counts['picking'] ?? 0) + (int) ($counts['part_dispatched'] ?? 0),
            ],
            'pending_approvals' => $approver ? $this->approvals->pendingCount($company->id) : null,
            'recent_orders' => $this->orders->page($company->id, ['status' => null, 'q' => null, 'from' => null, 'to' => null, 'sort' => 'placed_desc'], null, 5)['rows'],
            // Owners and approvers only (05.17 §2): never sent to a buyer or viewer.
            'finance' => $summary === null ? null : [
                'limit_minor' => $summary['limit_minor'],
                'available_minor' => $summary['available_minor'],
                'over_limit_minor' => $summary['over_limit_minor'],
                'balance_minor' => $summary['balance_minor'],
                'overdue' => $summary['overdue'],
            ],
        ];
    }
}
