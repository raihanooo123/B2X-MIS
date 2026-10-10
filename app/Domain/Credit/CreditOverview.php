<?php

namespace App\Domain\Credit;

use App\Models\AccountCreditPayout;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 05.2 §18.3 — what the account credit and balance pages show: the limit,
 * used, held, available and balance; whether on account is open and why
 * not; overdue debt and its ageing; outstanding invoices and the balance
 * ledger, each keyset-paged. Read only. Integer pence throughout.
 */
final class CreditOverview
{
    public const PAGE_SIZE = 50;

    /** Days past due: upper bound of each bucket (05.2 §9). */
    private const BUCKETS = [
        'current' => 'Not yet due',
        'days_1_30' => '1–30 days overdue',
        'days_31_60' => '31–60 days overdue',
        'days_61_90' => '61–90 days overdue',
        'days_90_plus' => 'Over 90 days overdue',
    ];

    public function __construct(
        private readonly CreditGate $gate = new CreditGate,
        private readonly CreditSettings $settings = new CreditSettings,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Company $company): array
    {
        $available = $this->gate->available($company);
        $refusal = $this->gate->companyRefusal($company, 'on_account');
        $overdue = $this->gate->overdueInvoices($company->id);
        $oldest = (clone $overdue)->min('due_at');

        return [
            'status' => $company->status,
            'suspension_reason' => $company->status === 'suspended' ? $this->settings->suspensionReason($company->id) : null,
            'payment_terms' => $company->payment_terms,
            'limit_minor' => $company->credit_limit_minor,
            'used_minor' => $company->credit_used_minor,
            'held_minor' => $company->credit_held_minor,
            // A reduced limit can leave raw availability negative: show 0 and the excess (05.2 §18.1).
            'available_minor' => max(0, $available),
            'over_limit_minor' => max(0, -$available),
            'balance_minor' => $company->account_balance_minor,
            'on_account' => ['allowed' => $refusal === null, 'code' => $refusal?->reason, 'message' => $refusal?->getMessage()],
            'overdue' => [
                'count' => (clone $overdue)->count(),
                'amount_minor' => (int) (clone $overdue)->sum(DB::raw('total_gross_minor - paid_minor - credited_minor')),
                'oldest_due_at' => $oldest === null ? null : Carbon::parse((string) $oldest)->toIso8601ZuluString(),
            ],
            'ageing' => $this->ageing($company->id),
        ];
    }

    /** @return list<array{bucket: string, label: string, count: int, amount_minor: int}> */
    public function ageing(int $companyId): array
    {
        $row = CreditGate::outstanding(Invoice::query()->where('company_id', $companyId))->toBase()->selectRaw(<<<'SQL'
            count(*) FILTER (WHERE due_at IS NULL OR due_at >= now()) AS current_n,
            coalesce(sum(total_gross_minor - paid_minor - credited_minor) FILTER (WHERE due_at IS NULL OR due_at >= now()), 0) AS current_amount,
            count(*) FILTER (WHERE due_at < now() AND due_at >= now() - interval '30 days') AS days_1_30_n,
            coalesce(sum(total_gross_minor - paid_minor - credited_minor) FILTER (WHERE due_at < now() AND due_at >= now() - interval '30 days'), 0) AS days_1_30_amount,
            count(*) FILTER (WHERE due_at < now() - interval '30 days' AND due_at >= now() - interval '60 days') AS days_31_60_n,
            coalesce(sum(total_gross_minor - paid_minor - credited_minor) FILTER (WHERE due_at < now() - interval '30 days' AND due_at >= now() - interval '60 days'), 0) AS days_31_60_amount,
            count(*) FILTER (WHERE due_at < now() - interval '60 days' AND due_at >= now() - interval '90 days') AS days_61_90_n,
            coalesce(sum(total_gross_minor - paid_minor - credited_minor) FILTER (WHERE due_at < now() - interval '60 days' AND due_at >= now() - interval '90 days'), 0) AS days_61_90_amount,
            count(*) FILTER (WHERE due_at < now() - interval '90 days') AS days_90_plus_n,
            coalesce(sum(total_gross_minor - paid_minor - credited_minor) FILTER (WHERE due_at < now() - interval '90 days'), 0) AS days_90_plus_amount
            SQL)->first();

        $buckets = [];
        foreach (self::BUCKETS as $bucket => $label) {
            $buckets[] = [
                'bucket' => $bucket,
                'label' => $label,
                'count' => (int) ($row->{$bucket.'_n'} ?? 0),
                'amount_minor' => (int) ($row->{$bucket.'_amount'} ?? 0),
            ];
        }

        return $buckets;
    }

    /**
     * Outstanding invoices, oldest due first (invoices_credit_unpaid_idx).
     *
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function invoices(int $companyId, ?array $after): array
    {
        $query = CreditGate::outstanding(Invoice::query()->where('company_id', $companyId))
            ->orderByRaw('due_at ASC NULLS LAST')->orderBy('id');
        if ($after !== null) {
            $query->whereRaw('(due_at, id) > (?, ?)', [$after['v'], $after['id']]);
        }
        $rows = $query->limit(self::PAGE_SIZE + 1)->get(['id', 'public_id', 'invoice_number', 'issued_at', 'due_at', 'status', 'total_gross_minor', 'paid_minor', 'credited_minor']);
        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        $last = $rows->last();
        $now = now();

        return [
            'rows' => array_values($rows->map(fn (Invoice $i): array => [
                'id' => $i->public_id,
                'invoice_number' => $i->invoice_number,
                'issued_at' => $i->issued_at->toIso8601ZuluString(),
                'due_at' => $i->due_at?->toIso8601ZuluString(),
                'days_overdue' => $i->due_at !== null && $i->due_at->lessThan($now) ? (int) $i->due_at->diffInDays($now) : 0,
                'total_gross_minor' => $i->total_gross_minor,
                'paid_minor' => $i->paid_minor,
                'credited_minor' => $i->credited_minor,
                'outstanding_minor' => $i->total_gross_minor - $i->paid_minor - $i->credited_minor,
            ])->all()),
            // Invoices with no due date sort last and end the list.
            'next' => $more && $last !== null && $last->due_at !== null ? ['v' => (string) $last->getRawOriginal('due_at'), 'id' => $last->id] : null,
        ];
    }

    /**
     * The balance ledger, newest first (account_credit_movements_company_idx).
     *
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function movements(int $companyId, ?array $after): array
    {
        $query = DB::table('account_credit_movements AS m')
            ->leftJoin('credit_notes AS cn', 'cn.id', '=', 'm.credit_note_id')
            ->leftJoin('invoices AS i', function ($join): void {
                $join->on('i.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'invoice');
            })
            ->leftJoin('orders AS o', 'o.id', '=', 'm.order_id')
            ->where('m.company_id', $companyId)
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')
            ->select(['m.id', 'm.occurred_at', 'm.movement_type', 'm.amount_minor', 'm.balance_after_minor', 'm.reason_code',
                'cn.credit_note_number', 'i.invoice_number', 'o.order_number']);
        if ($after !== null) {
            $query->whereRaw('(m.occurred_at, m.id) < (?, ?)', [$after['v'], $after['id']]);
        }
        $rows = $query->limit(self::PAGE_SIZE + 1)->get();
        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        $last = $rows->last();

        return [
            'rows' => array_values($rows->map(fn (object $m): array => [
                'id' => (int) $m->id,
                'occurred_at' => Carbon::parse((string) $m->occurred_at)->toIso8601ZuluString(),
                'type' => (string) $m->movement_type,
                'reference' => $m->credit_note_number ?? $m->invoice_number ?? $m->order_number,
                'amount_minor' => (int) $m->amount_minor,
                'balance_after_minor' => $m->balance_after_minor === null ? null : (int) $m->balance_after_minor,
            ])->all()),
            'next' => $more && $last !== null ? ['v' => (string) $last->occurred_at, 'id' => (int) $last->id] : null,
        ];
    }

    /** @return list<array<string, mixed>> the latest payouts, newest first */
    public function payouts(int $companyId): array
    {
        return array_values(AccountCreditPayout::query()->where('company_id', $companyId)->orderByDesc('requested_at')->orderByDesc('id')->limit(20)
            ->get()->map(fn (AccountCreditPayout $p): array => [
                'id' => $p->public_id,
                'method' => $p->method,
                'amount_minor' => $p->amount_minor,
                'status' => $p->status,
                'requested_at' => $p->requested_at->toIso8601ZuluString(),
                'completed_at' => $p->completed_at?->toIso8601ZuluString(),
            ])->all());
    }
}
