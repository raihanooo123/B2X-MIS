<?php

namespace App\Domain\Ordering;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderApprovalRequest;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\StockAllocation;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 05.17 §2 — a trade company's order history and order detail, for every
 * member of the company. Read only.
 *
 * History is keyset paged on (placed_at, id) — orders_trade_history_cursor_idx,
 * read forwards for newest first and backwards for oldest first — 50 a page
 * by default, 100 at most. Orders not yet placed (`placed_at` NULL) are
 * never in history; they are listed separately as drafts.
 *
 * Detail reads the immutable snapshots on the order, its lines, shipments
 * and documents: nothing is re-priced, no cost, staff note, storage key or
 * accounting id leaves this class. Invoice and credit note links appear
 * only for members who may see financial documents.
 */
final class TradeOrders
{
    public const PAGE_SIZE = 50;

    public const MAX_PAGE_SIZE = 100;

    public const SORTS = ['placed_desc' => 'desc', 'placed_asc' => 'asc'];

    /** filter value => order statuses */
    public const STATUS_GROUPS = [
        'awaiting_approval' => ['awaiting_approval'],
        'pending_payment' => ['pending_payment'],
        'in_progress' => ['confirmed', 'picking', 'part_dispatched'],
        'dispatched' => ['dispatched', 'completed'],
        'cancelled' => ['cancelled'],
    ];

    /**
     * @param  array{status: ?string, q: ?string, from: ?string, to: ?string, sort: string}  $filters
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function page(int $companyId, array $filters, ?array $after, int $perPage = self::PAGE_SIZE): array
    {
        $perPage = max(1, min($perPage, self::MAX_PAGE_SIZE));
        $direction = self::SORTS[$filters['sort']] ?? 'desc';

        $query = Order::query()->where('company_id', $companyId)->whereNotNull('placed_at');
        $this->filter($query, $filters);
        $query->orderBy('placed_at', $direction)->orderBy('id', $direction)
            ->with(['placedBy:id,first_name,last_name', 'user:id,first_name,last_name']);

        if ($after !== null) {
            $operator = $direction === 'desc' ? '<' : '>';
            $query->whereRaw("(placed_at, id) {$operator} (?, ?)", [$after['v'], $after['id']]);
        }

        $rows = $query->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $last = $rows->last();

        return [
            'rows' => array_values($rows->map(fn (Order $o): array => $this->row($o))->all()),
            // Microsecond precision survives as PostgreSQL's own text.
            'next' => $more && $last !== null ? ['v' => (string) $last->getRawOriginal('placed_at'), 'id' => $last->id] : null,
        ];
    }

    /**
     * Orders started but not placed — never in history (05.17 §2).
     *
     * @return list<array<string, mixed>>
     */
    public function drafts(int $companyId): array
    {
        return array_values(Order::query()->where('company_id', $companyId)->whereNull('placed_at')
            ->where('status', '<>', 'cancelled')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)
            ->with(['placedBy:id,first_name,last_name', 'user:id,first_name,last_name'])->get()
            ->map(fn (Order $o): array => $this->row($o))->all());
    }

    /** @return array<string, mixed> */
    public function detail(Order $order, User $viewer, bool $finance): array
    {
        $order->loadMissing(['placedBy:id,first_name,last_name', 'user:id,first_name,last_name']);
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->orderBy('id')->get();
        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $addresses = OrderAddress::query()->where('order_id', $order->id)->get()->keyBy('address_type');
        $approvals = OrderApprovalRequest::query()->with('decidedBy:id,first_name,last_name')->where('order_id', $order->id)->orderBy('id')->get();
        $stockReserved = StockAllocation::query()->whereIn('order_line_id', $lines->pluck('id'))->whereIn('status', ['allocated', 'picked'])->exists();
        $role = CompanyUser::query()->where('company_id', $order->company_id)->where('user_id', $viewer->id)->value('role');

        return [
            ...$this->row($order),
            'fulfilment_type' => $order->fulfilment_type,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'stock_reserved' => $stockReserved,
            'delivery_address' => self::addressLines($addresses->get('delivery')),
            'billing_address' => self::addressLines($addresses->get('billing')),
            'totals' => [
                'subtotal_net_minor' => $order->subtotal_net_minor,
                'discount_net_minor' => $order->discount_net_minor,
                'shipping_net_minor' => $order->shipping_net_minor,
                'tax_minor' => $order->tax_minor,
                'total_gross_minor' => $order->total_gross_minor,
            ],
            'lines' => array_values($lines->map(fn (OrderLine $l): array => [
                'line_no' => $l->line_no,
                'sku_code' => $l->sku_code_snapshot,
                'name' => $l->name_snapshot,
                'pack' => $l->pack_label_snapshot,
                'pack_qty' => $l->pack_qty,
                'pack_base_units' => $l->pack_base_units,
                'base_qty' => $l->base_qty,
                'dispatched_base_qty' => $l->dispatched_base_qty,
                'cancelled_base_qty' => $l->cancelled_base_qty,
                'returned_base_qty' => $l->returned_base_qty,
                'unit_price_net_e4' => $l->unit_price_net_e4,
                'tax_rate_bp' => $l->tax_rate_bp,
                'line_net_minor' => $l->line_net_minor,
                'line_tax_minor' => $l->line_tax_minor,
                'line_gross_minor' => $l->line_gross_minor,
            ])->all()),
            'shipments' => array_values($shipments->map(fn (Shipment $s): array => [
                'id' => $s->public_id,
                'status' => $s->status,
                'carrier' => $s->carrier,
                'tracking_number' => $s->tracking_number,
                'parcel_count' => $s->parcel_count,
                'picked_at' => $s->picked_at?->toIso8601ZuluString(),
                'dispatched_at' => $s->dispatched_at?->toIso8601ZuluString(),
            ])->all()),
            'timeline' => $this->timeline($order, $shipments, $approvals),
            'approvals' => array_values($approvals->map(fn (OrderApprovalRequest $r): array => [
                'kind' => $r->approval_kind,
                'status' => $r->status,
                'requested_at' => $r->requested_at->toIso8601ZuluString(),
                'decided_at' => $r->decided_at?->toIso8601ZuluString(),
                'decided_by' => $r->decidedBy === null ? null : trim("{$r->decidedBy->first_name} {$r->decidedBy->last_name}"),
            ])->all()),
            // Financial documents only for owners/approvers (05.17 §2).
            'invoices' => $finance ? array_values(Invoice::query()->where('order_id', $order->id)->where('company_id', $order->company_id)
                ->orderBy('issued_at')->orderBy('id')->get(['public_id', 'invoice_number', 'status', 'issued_at', 'total_gross_minor'])
                ->map(fn (Invoice $i): array => [
                    'id' => $i->public_id,
                    'number' => $i->invoice_number,
                    'status' => $i->status,
                    'issued_at' => $i->issued_at->toIso8601ZuluString(),
                    'total_gross_minor' => $i->total_gross_minor,
                ])->all()) : null,
            'credit_notes' => $finance ? array_values(CreditNote::query()->where('order_id', $order->id)->where('company_id', $order->company_id)
                ->orderBy('issued_at')->orderBy('id')->get(['public_id', 'credit_note_number', 'issued_at', 'total_gross_minor'])
                ->map(fn (CreditNote $n): array => [
                    'id' => $n->public_id,
                    'number' => $n->credit_note_number,
                    'issued_at' => $n->issued_at->toIso8601ZuluString(),
                    'total_gross_minor' => $n->total_gross_minor,
                ])->all()) : null,
            'viewer_role' => $role,
        ];
    }

    /** @return array<string, mixed> */
    private function row(Order $o): array
    {
        $by = $o->placedBy ?? $o->user;

        return [
            'id' => $o->public_id,
            'order_number' => $o->order_number,
            'customer_reference' => $o->customer_reference,
            'status' => $o->status,
            'status_label' => self::statusLabel($o->status),
            'placed_at' => $o->placed_at?->toIso8601ZuluString(),
            'placed_by' => $by === null ? null : trim("{$by->first_name} {$by->last_name}"),
            'payment_status' => $o->payment_status,
            'total_gross_minor' => $o->total_gross_minor,
        ];
    }

    /**
     * Plain words that keep the four states apart (05.17 §2): waiting for
     * payment, waiting for approval with stock not yet reserved, in
     * progress, and sent.
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Not placed',
            'awaiting_approval' => 'Awaiting approval — stock not reserved',
            'pending_payment' => 'Awaiting payment',
            'confirmed' => 'Confirmed',
            'picking' => 'Being picked',
            'part_dispatched' => 'Partly dispatched',
            'dispatched' => 'Dispatched',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @param  Collection<int, OrderApprovalRequest>  $approvals
     * @return list<array{at: string, label: string}>
     */
    private function timeline(Order $order, Collection $shipments, Collection $approvals): array
    {
        $events = [];
        if ($order->placed_at !== null) {
            $events[] = [$order->placed_at, 'Order placed'];
        }
        foreach ($approvals as $r) {
            $events[] = [$r->requested_at, 'Approval requested'];
            if ($r->decided_at !== null) {
                $events[] = [$r->decided_at, match ($r->status) {
                    'approved' => 'Approved',
                    'rejected' => 'Approval refused',
                    'expired' => 'Approval request expired',
                    default => 'Approval decided',
                }];
            }
        }
        foreach ($shipments as $s) {
            if ($s->picked_at !== null) {
                $events[] = [$s->picked_at, 'Picked'];
            }
            if ($s->dispatched_at !== null) {
                $events[] = [$s->dispatched_at, $s->fulfilment_type === 'collection' ? 'Collected' : 'Dispatched'];
            }
        }
        if ($order->cancelled_at !== null) {
            $events[] = [$order->cancelled_at, 'Cancelled'];
        }
        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $e): array => ['at' => $e[0]->toIso8601ZuluString(), 'label' => $e[1]], $events);
    }

    /**
     * @param  Builder<Order>  $query
     * @param  array{status: ?string, q: ?string, from: ?string, to: ?string, sort: string}  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        if ($filters['status'] !== null && isset(self::STATUS_GROUPS[$filters['status']])) {
            $query->whereIn('status', self::STATUS_GROUPS[$filters['status']]);
        }
        if ($filters['q'] !== null) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('order_number', 'ilike', $like)->orWhere('customer_reference', 'ilike', $like));
        }
        // UK calendar days; stored instants are UTC (05.16 §5).
        if ($filters['from'] !== null) {
            $query->where('placed_at', '>=', CarbonImmutable::parse($filters['from'], DisplayTime::zone())->startOfDay()->utc());
        }
        if ($filters['to'] !== null) {
            $query->where('placed_at', '<', CarbonImmutable::parse($filters['to'], DisplayTime::zone())->addDay()->startOfDay()->utc());
        }
    }

    /** @return list<string> */
    private static function addressLines(?OrderAddress $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_values(array_filter([
            $address->contact_name, $address->company_name, $address->line1, $address->line2,
            $address->city, $address->county, $address->postcode, $address->country_code === 'GB' ? null : $address->country_code,
        ], fn (?string $part) => $part !== null && trim($part) !== ''));
    }

    /** The acting company's order with this public id, or null — never another company's. */
    public static function find(Company $company, string $publicId): ?Order
    {
        return Order::query()->where('public_id', $publicId)->where('company_id', $company->id)->first();
    }
}
