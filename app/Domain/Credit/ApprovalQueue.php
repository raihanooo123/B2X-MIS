<?php

namespace App\Domain\Credit;

use App\Models\CompanyUser;
use App\Models\OrderAddress;
use App\Models\OrderApprovalRequest;
use App\Models\OrderLine;
use App\Models\StockAllocation;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * 05.2 §18.3 — the approval queue and request detail for a company's
 * owners and approvers: read only, nothing decided on a GET. Default sort
 * requested_at DESC, id DESC (order_approvals_company_queue_idx); keyset
 * "Load more", 50 a page. Timestamps leave as UTC ISO 8601; the page
 * shows them in UK time.
 */
final class ApprovalQueue
{
    public const PAGE_SIZE = 50;

    /** sort key => [column, direction] */
    public const SORTS = [
        'requested_desc' => ['requested_at', 'desc'],
        'expires_asc' => ['expires_at', 'asc'],
        'gross_desc' => ['order_gross_minor', 'desc'],
    ];

    /**
     * @param  array{status: string, buyer: ?string, from: ?string, to: ?string, q: ?string, sort: string}  $filters
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function page(int $companyId, User $viewer, array $filters, ?array $after): array
    {
        [$column, $direction] = self::SORTS[$filters['sort']] ?? self::SORTS['requested_desc'];
        $query = $this->filtered($companyId, $filters)
            ->with(['buyer:id,public_id,first_name,last_name', 'decidedBy:id,first_name,last_name', 'order:id,public_id,order_number,status,payment_method'])
            ->orderBy($column, $direction)->orderBy('id', $direction);

        if ($after !== null) {
            $operator = $direction === 'desc' ? '<' : '>';
            $query->whereRaw("({$column}, id) {$operator} (?, ?)", [$after['v'], $after['id']]);
        }

        $rows = $query->limit(self::PAGE_SIZE + 1)->get();
        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        $last = $rows->last();
        // One membership check for the page, not one policy query per row.
        $approver = $viewer->status === 'active' && ! $viewer->isStaff() && CompanyUser::query()->where('company_id', $companyId)
            ->where('user_id', $viewer->id)->whereIn('role', ['owner', 'approver'])->exists();

        return [
            'rows' => array_values($rows->map(fn (OrderApprovalRequest $r): array => $this->row($r, $approver
                && $r->status === ApprovalStatus::Pending->value && $r->approval_kind === ApprovalKind::BuyerLimit->value
                && $r->requested_by_user_id !== $viewer->id))->all()),
            // Timestamps keep PostgreSQL's microseconds, so the tuple compares exactly.
            'next' => $more && $last !== null ? ['v' => $column === 'order_gross_minor' ? $last->order_gross_minor : (string) $last->getRawOriginal($column), 'id' => $last->id] : null,
        ];
    }

    /** @return list<array{id: string, name: string}> the company's buying members, for the buyer filter */
    public function buyers(int $companyId): array
    {
        return array_values(User::query()->whereIn('id', CompanyUser::query()->where('company_id', $companyId)->select('user_id'))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get(['public_id', 'first_name', 'last_name'])
            ->map(fn (User $u): array => ['id' => (string) $u->public_id, 'name' => trim("{$u->first_name} {$u->last_name}")])
            ->all());
    }

    public function pendingCount(int $companyId): int
    {
        return OrderApprovalRequest::query()->where('company_id', $companyId)->where('status', ApprovalStatus::Pending->value)->count();
    }

    /** @return array<string, mixed> */
    public function detail(OrderApprovalRequest $request, User $viewer): array
    {
        $request->loadMissing(['buyer', 'order', 'company']);
        $order = $request->order;
        $member = CompanyUser::query()->where('company_id', $request->company_id)->where('user_id', $request->requested_by_user_id)->first();
        $all = OrderApprovalRequest::query()->with('decidedBy:id,first_name,last_name')->where('order_id', $request->order_id)->orderBy('id')->get();
        $stockReserved = $order !== null && StockAllocation::query()
            ->whereIn('order_line_id', OrderLine::query()->where('order_id', $order->id)->select('id'))
            ->whereIn('status', ['allocated', 'picked'])->exists();
        $address = $order === null ? null : OrderAddress::query()->where('order_id', $order->id)->where('address_type', 'delivery')->first();

        return [
            ...$this->row($request, $request->status === ApprovalStatus::Pending->value && Gate::forUser($viewer)->allows('decide', $request)),
            'company' => ['name' => $request->company->name ?? '', 'account_code' => $request->company->account_code ?? ''],
            'buyer' => [
                'name' => $this->name($request->buyer),
                'email' => $request->buyer->email ?? '',
                'role' => $member->role ?? null,
                'order_limit_minor' => $member?->order_limit_minor,
                'requires_approval' => (bool) ($member->requires_approval ?? false),
            ],
            'order' => $order === null ? null : [
                'id' => $order->public_id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'customer_reference' => $order->customer_reference,
                'payment_method' => $order->payment_method,
                'fulfilment_type' => $order->fulfilment_type,
                'placed_at' => $order->placed_at?->toIso8601ZuluString(),
                'subtotal_net_minor' => $order->subtotal_net_minor,
                'shipping_net_minor' => $order->shipping_net_minor,
                'tax_minor' => $order->tax_minor,
                'total_gross_minor' => $order->total_gross_minor,
                'delivery' => $address === null ? null : array_values(array_filter([
                    $address->contact_name, $address->company_name, $address->line1, $address->line2, $address->city, $address->postcode,
                ], fn ($v) => is_string($v) && $v !== '')),
                'lines' => array_values(OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->orderBy('id')->get()
                    ->map(fn (OrderLine $l): array => [
                        'sku_code' => $l->sku_code_snapshot,
                        'name' => $l->name_snapshot,
                        'pack' => $l->pack_label_snapshot,
                        'pack_qty' => $l->pack_qty,
                        'base_qty' => $l->base_qty,
                        'unit_price_net_e4' => $l->unit_price_net_e4,
                        'line_net_minor' => $l->line_net_minor,
                        'line_tax_minor' => $l->line_tax_minor,
                        'line_gross_minor' => $l->line_gross_minor,
                    ])->all()),
            ],
            'stock_reserved' => $stockReserved,
            'requests' => array_values($all->map(fn (OrderApprovalRequest $r): array => [
                'kind' => $r->approval_kind,
                'status' => $r->status,
                'decided_by' => $r->decidedBy === null ? null : $this->name($r->decidedBy),
                'decided_at' => $r->decided_at?->toIso8601ZuluString(),
                'reason' => $r->decision_reason,
            ])->all()),
        ];
    }

    /**
     * @param  array{status: string, buyer: ?string, from: ?string, to: ?string, q: ?string, sort: string}  $filters
     * @return Builder<OrderApprovalRequest>
     */
    private function filtered(int $companyId, array $filters): Builder
    {
        $query = OrderApprovalRequest::query()->where('company_id', $companyId);
        $query = $filters['status'] === 'decided'
            ? $query->where('status', '<>', ApprovalStatus::Pending->value)
            : $query->where('status', ApprovalStatus::Pending->value);

        if ($filters['buyer'] !== null) {
            $query->whereIn('requested_by_user_id', User::query()->where('public_id', $filters['buyer'])->select('id'));
        }
        // UK calendar days (05.16 §5); stored instants are UTC.
        if ($filters['from'] !== null) {
            $query->where('requested_at', '>=', CarbonImmutable::parse($filters['from'], DisplayTime::zone())->startOfDay()->utc());
        }
        if ($filters['to'] !== null) {
            $query->where('requested_at', '<', CarbonImmutable::parse($filters['to'], DisplayTime::zone())->addDay()->startOfDay()->utc());
        }
        if ($filters['q'] !== null) {
            $query->whereHas('order', fn (Builder $q) => $q->where('order_number', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%'));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(OrderApprovalRequest $r, bool $canDecide): array
    {
        return [
            'id' => $r->public_id,
            'kind' => $r->approval_kind,
            'status' => $r->status,
            'order_number' => $r->order->order_number ?? '',
            'order_status' => $r->order->status ?? null,
            'payment_method' => $r->order->payment_method ?? null,
            'buyer_name' => $this->name($r->buyer),
            'gross_minor' => $r->order_gross_minor,
            'requested_at' => $r->requested_at->toIso8601ZuluString(),
            'expires_at' => $r->expires_at->toIso8601ZuluString(),
            'decided_at' => $r->decided_at?->toIso8601ZuluString(),
            'decided_by' => $r->decidedBy === null ? null : $this->name($r->decidedBy),
            'decision_reason' => $r->decision_reason,
            // UI help only; the API re-checks (06 §18).
            'can_decide' => $canDecide,
        ];
    }

    private function name(?User $user): string
    {
        return $user === null ? '' : trim("{$user->first_name} {$user->last_name}");
    }
}
