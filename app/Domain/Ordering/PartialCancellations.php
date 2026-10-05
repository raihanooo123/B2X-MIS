<?php

namespace App\Domain\Ordering;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Refunds;
use App\Domain\Billing\RefundSettlement;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\MovementAttribution;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Pricing\Money;
use App\Domain\Reference\NumberSequenceService;
use App\Domain\Warehouse\FulfilmentRules;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationLine;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\StockAllocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 05.10 §2 — cancelling some quantity of an order before it is dispatched.
 *
 * What: whole packs of a line's pack, up to what is neither dispatched,
 * cancelled already, nor in a shipment that is `packed` (409 `line_packed`).
 * Orders in `confirmed`, `picking` or `part_dispatched`; never a
 * replacement (05.4 §14). A card payment still only authorised is refused
 * until it is captured, as checkout captures straight away.
 *
 * Break quantity (Q-X1): on a trade order a **customer** may not leave a
 * line below the quantity of the price break that priced it
 * (`applied_break_qty`): what is kept must be 0 or at least that. Staff
 * may, with `reason_code = 'below_break_override'` and a reason, audited
 * as `order.cancel_below_break`. A consumer is never refused for this: a
 * statutory right (CCR reg. 29). Nobody is re-priced (invariant 4).
 *
 * Money (§2.2): a line's cancelled share is `billable(C_before) −
 * billable(C_after)` with `billable(C) = T` when C = 0, else
 * `round_half_up(T × (B − C) / B)`, for net and tax separately. Per-shipment
 * invoices keep using T over B, so invoices and cancellations of a line
 * telescope to exactly T. The delivery charge stands — unless nothing is
 * left to send and nothing was sent, when the order is cancelled whole and
 * the delivery is cancelled with it. Then, in this transaction:
 *   - on account: the credit hold falls by the gross (05.2 §8.3), released at 0;
 *   - a captured payment: a pending refund row (card refunded after commit;
 *     bank transfer paid by accounts);
 *   - a whole-order invoice or receipt already issued: a credit note
 *     `cancellation` against it.
 * An unpaid order just owes less.
 *
 * The transaction (§2.3), retried whole on deadlock (04 §4.5): `companies`
 * (on account only) → `orders` → `shipments` → `stock_allocations`
 * (ascending id) → `stock_levels` (02 §11.1, inside DeallocationService)
 * → `payments` → `number_sequences` (last). Dispatch locks `orders` first
 * too, so the two serialise. Idempotent on `client_token`. No gateway call
 * inside (invariant 6).
 */
final class PartialCancellations
{
    public const MOVEMENT_REASON = 'order_items_cancelled';

    public const BELOW_BREAK_OVERRIDE = 'below_break_override';

    private const STATUSES = ['confirmed', 'picking', 'part_dispatched'];

    public function __construct(
        private readonly DeallocationService $deallocation = new DeallocationService,
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @param  array<int, int>  $packs  line_no => packs to cancel
     * @param  string  $initiatedBy  `customer` or `staff`
     *
     * @throws OrderNotCancellableException
     */
    public function cancel(
        int $orderId,
        array $packs,
        string $initiatedBy,
        ?int $actorUserId,
        CarbonImmutable $notifiedAt,
        ?string $reasonCode = null,
        ?string $reasonDetail = null,
        ?string $clientToken = null,
    ): OrderCancellation {
        if (! in_array($initiatedBy, ['customer', 'staff'], true)
            || ($initiatedBy === 'staff' && ($actorUserId === null || ! User::query()->findOrFail($actorUserId)->isStaff()))) {
            throw new OrderNotCancellableException('not_authorized', 'This cancellation cannot be recorded by this actor.');
        }
        if ($reasonDetail !== null && mb_strlen(trim($reasonDetail)) > 500) {
            throw new OrderNotCancellableException('reason_too_long', 'Keep the reason within 500 characters.');
        }
        if (count($packs) !== count(array_filter($packs, fn (int $n): bool => $n >= 0))) {
            throw new OrderNotCancellableException('invalid_quantity', 'A cancellation quantity cannot be negative.');
        }
        $packs = array_filter($packs, fn (int $n): bool => $n > 0);
        if ($packs === []) {
            throw new OrderNotCancellableException('nothing_selected', 'Choose at least one item to cancel.');
        }
        $reasonDetail = $reasonDetail === null || trim($reasonDetail) === '' ? null : trim($reasonDetail);

        /** @var array{cancellation: OrderCancellation, refunds: list<int>} $outcome */
        $outcome = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->cancelWithinTransaction($orderId, $packs, $initiatedBy, $actorUserId, $notifiedAt, $reasonCode, $reasonDetail, $clientToken)),
            self::class,
        );

        (new RefundSettlement($this->notifications))->settle($outcome['refunds']);

        return $outcome['cancellation'];
    }

    /**
     * @param  array<int, int>  $packs
     * @return array{cancellation: OrderCancellation, refunds: list<int>}
     */
    private function cancelWithinTransaction(int $orderId, array $packs, string $initiatedBy, ?int $actorUserId, CarbonImmutable $notifiedAt, ?string $reasonCode, ?string $reasonDetail, ?string $clientToken): array
    {
        // 1. companies first — only when this order's credit hold moves (02 §11.1).
        $peek = Order::query()->where('id', $orderId)->firstOrFail(['id', 'company_id', 'payment_method']);
        $onAccount = $peek->company_id !== null && $peek->payment_method === PaymentMethod::OnAccount->value;
        if ($onAccount) {
            Company::query()->where('id', $peek->company_id)->lockForUpdate()->firstOrFail(['id']);
        }

        // 2. orders.
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->firstOrFail();
        if ($clientToken !== null) {
            $repeat = OrderCancellation::query()->where('order_id', $orderId)->where('client_token', $clientToken)->first();
            if ($repeat !== null) {
                return ['cancellation' => $repeat, 'refunds' => []];
            }
        }
        $this->assertCancellable($order);
        $consumer = $order->company_id === null;

        // 3. shipments: quantity already packed cannot be cancelled.
        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $packedLocations = $shipments->where('status', 'packed')->pluck('location_id')->map(fn ($id) => (int) $id)->all();

        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('line_no');

        // 4. stock_allocations of the order, ascending id.
        $allocations = StockAllocation::query()
            ->whereIn('order_line_id', $lines->pluck('id'))
            ->whereIn('status', FulfilmentRules::ACTIVE_ALLOCATION_STATUSES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $belowBreak = false;
        $releases = [];
        $plan = [];
        foreach ($packs as $lineNo => $packQty) {
            $line = $lines->get($lineNo) ?? throw new OrderNotCancellableException('unknown_line', "This order has no line {$lineNo}.");
            $qty = $packQty * $line->pack_base_units;
            $mine = $allocations->where('order_line_id', $line->id);
            $packed = (int) $mine->filter(fn (StockAllocation $a) => in_array((int) $a->location_id, $packedLocations, true))->sum('base_qty');
            $outstanding = $line->base_qty - $line->dispatched_base_qty - $line->cancelled_base_qty;
            $cancellable = $outstanding - $packed;

            if ($qty > $cancellable) {
                throw new OrderNotCancellableException($packed > 0 && $qty <= $outstanding ? 'line_packed' : 'too_many',
                    $packed > 0 && $qty <= $outstanding
                        ? "{$line->name_snapshot}: some of it is already packed. Ask us to unpack it first."
                        : "{$line->name_snapshot}: only ".intdiv(max(0, $cancellable), $line->pack_base_units)." {$line->pack_label_snapshot} pack(s) can still be cancelled.");
            }

            // Q-X1: a trade customer may not keep less than the break that priced the line (0 is fine).
            $kept = $line->base_qty - $line->cancelled_base_qty - $qty;
            $break = (int) ($line->applied_break_qty ?? 0);
            if (! $consumer && $break > 1 && $kept > 0 && $kept < $break) {
                if ($initiatedBy !== 'staff') {
                    throw new OrderNotCancellableException('below_break_quantity', "{$line->name_snapshot} was priced for {$break} or more. Keep at least {$break}, or cancel the whole line.");
                }
                if ($reasonCode !== self::BELOW_BREAK_OVERRIDE || $reasonDetail === null) {
                    throw new OrderNotCancellableException('below_break_quantity', "{$line->name_snapshot} was priced for {$break} or more. To go below it, record the reason as an override.");
                }
                $belowBreak = true;
            }

            // Release: unallocated quantity first (nothing to release), then
            // unpicked allocations, then picked ones, each newest first; never packed.
            $unallocated = max(0, $outstanding - (int) $mine->sum('base_qty'));
            $toRelease = max(0, $qty - $unallocated);
            $candidates = $mine->reject(fn (StockAllocation $a) => in_array((int) $a->location_id, $packedLocations, true))
                ->sortBy([fn ($a, $b) => ($a->status === 'picked') <=> ($b->status === 'picked'), fn ($a, $b) => $b->id <=> $a->id]);
            foreach ($candidates as $allocation) {
                if ($toRelease === 0) {
                    break;
                }
                $take = min($toRelease, $allocation->base_qty);
                $releases[$allocation->id] = $take;
                $toRelease -= $take;
            }

            $plan[] = [$line, $packQty, $qty];
        }

        if ($releases !== []) {
            $this->deallocation->releasePartWithinTransaction($releases, new MovementAttribution(
                $actorUserId, self::MOVEMENT_REASON, "Items of order {$order->order_number} cancelled before dispatch (05.10 §2)",
            ));
        }

        // Shares and the line updates.
        $net = 0;
        $tax = 0;
        $shares = [];
        foreach ($plan as [$line, $packQty, $qty]) {
            $before = $line->cancelled_base_qty;
            $after = $before + $qty;
            $lineNet = self::billable($line->line_net_minor, $before, $line->base_qty) - self::billable($line->line_net_minor, $after, $line->base_qty);
            $lineTax = self::billable($line->line_tax_minor, $before, $line->base_qty) - self::billable($line->line_tax_minor, $after, $line->base_qty);
            $line->forceFill(['cancelled_base_qty' => $after])->save();
            $shares[] = [$line, $packQty, $qty, $lineNet, $lineTax];
            $net += $lineNet;
            $tax += $lineTax;
        }

        // Status: nothing left to send?
        $remaining = OrderLine::query()->where('order_id', $order->id)
            ->whereRaw('dispatched_base_qty + cancelled_base_qty < base_qty')->exists();
        $anyDispatched = OrderLine::query()->where('order_id', $order->id)->where('dispatched_base_qty', '>', 0)->exists();
        $whole = ! $remaining && ! $anyDispatched;
        $deliveryNet = $whole ? $order->shipping_net_minor : 0;
        $deliveryTax = $whole ? $order->shipping_tax_minor : 0;

        if (! $remaining) {
            if ($whole) {
                Shipment::query()->whereIn('id', $shipments->pluck('id'))->whereNotIn('status', ['dispatched', 'cancelled'])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_fee_minor' => 0])->save();
            } else {
                $order->forceFill(['status' => 'dispatched'])->save();
            }
        }

        $gross = $net + $tax + $deliveryNet + $deliveryTax;
        $holdReduction = $onAccount ? $this->reduceCreditHold($order, $gross) : 0;
        [$refundId, $creditNote] = $this->money($order, $gross, $net + $deliveryNet, $tax + $deliveryTax);

        $cancellation = OrderCancellation::query()->create([
            'order_id' => $order->id,
            'kind' => $whole ? 'whole' : 'partial',
            'initiated_by' => $initiatedBy,
            'actor_user_id' => $actorUserId,
            'customer_notified_at' => $notifiedAt,
            'reason_code' => $reasonCode,
            'reason_detail' => $reasonDetail,
            'cancelled_net_minor' => $net,
            'cancelled_tax_minor' => $tax,
            'cancelled_gross_minor' => $net + $tax,
            'delivery_refund_net_minor' => $deliveryNet,
            'delivery_refund_tax_minor' => $deliveryTax,
            'credit_hold_reduction_minor' => $holdReduction,
            'credit_note_id' => $creditNote?->id,
            'refund_payment_id' => $refundId,
            'client_token' => $clientToken,
        ]);
        foreach ($shares as [$line, $packQty, $qty, $lineNet, $lineTax]) {
            OrderCancellationLine::query()->create([
                'order_cancellation_id' => $cancellation->id,
                'order_line_id' => $line->id,
                'cancelled_pack_qty' => $packQty,
                'cancelled_base_qty' => $qty,
                'line_net_minor' => $lineNet,
                'line_tax_minor' => $lineTax,
                'line_gross_minor' => $lineNet + $lineTax,
            ]);
        }

        if ($belowBreak) {
            $this->audit->record(new AuditEntry(
                action: AuditAction::OrderCancelBelowBreak,
                actorType: 'user',
                actorUserId: $actorUserId,
                companyId: $order->company_id,
                subjectType: 'order',
                subjectId: $order->id,
                after: ['order_cancellation_id' => $cancellation->id],
                reason: $reasonDetail,
            ));
        }

        $whole ? $this->notifications->orderCancelled($order->id) : $this->notifications->orderItemsCancelled($cancellation->id);

        return ['cancellation' => $cancellation, 'refunds' => $refundId === null ? [] : [$refundId]];
    }

    /** `billable(C)`: the line total left once C base units are cancelled (05.10 §2.2). */
    public static function billable(int $totalMinor, int $cancelledBaseQty, int $baseQty): int
    {
        return $cancelledBaseQty === 0 ? $totalMinor : Money::roundHalfUpDiv($totalMinor * ($baseQty - $cancelledBaseQty), $baseQty);
    }

    private function assertCancellable(Order $order): void
    {
        if ($order->order_kind === OrderKind::Replacement->value) {
            throw new OrderNotCancellableException('replacement_order', 'This is a replacement for faulty goods. Please contact us if you no longer want it.');
        }
        if ($order->status === 'cancelled') {
            throw new OrderNotCancellableException('already_cancelled', 'This order is already cancelled.');
        }
        if (! in_array($order->status, self::STATUSES, true)) {
            throw new OrderNotCancellableException('order_already_dispatched', 'Everything on this order has been sent. You can return it once it arrives.');
        }
        $authorisedOnly = Payment::query()->where('order_id', $order->id)->where('type', 'payment')->where('status', 'authorized')->exists();
        if ($authorisedOnly) {
            throw new OrderNotCancellableException('payment_in_progress', 'Your payment is still being completed. Please try again in a minute.');
        }
    }

    /** 05.2 §8.3: the order's live credit hold falls by the gross cancelled; at 0 it is released. */
    private function reduceCreditHold(Order $order, int $gross): int
    {
        $hold = CreditHold::query()->where('order_id', $order->id)->where('status', 'held')->lockForUpdate()->first();
        if ($hold === null || $gross === 0) {
            return 0;
        }

        $reduction = min($gross, $hold->amount_minor);
        if ($reduction === $hold->amount_minor) {
            $hold->forceFill(['status' => 'released', 'released_at' => now()])->save();
        } else {
            $hold->forceFill(['amount_minor' => $hold->amount_minor - $reduction])->save();
        }
        Company::query()->where('id', $order->company_id)->decrement('credit_held_minor', $reduction);

        return $reduction;
    }

    /**
     * The refund of a captured payment, and the credit note against a
     * whole-order invoice or receipt already issued.
     *
     * @return array{0: int|null, 1: CreditNote|null}
     */
    private function money(Order $order, int $gross, int $creditNet, int $creditTax): array
    {
        if ($gross === 0) {
            return [null, null];
        }

        $refundId = null;
        $payment = Payment::query()->where('order_id', $order->id)->where('type', 'payment')
            ->whereIn('status', ['captured', 'part_refunded'])->orderBy('id')->lockForUpdate()->first();
        if ($payment !== null) {
            $amount = min($gross, $payment->amount_minor - Refunds::refundedOrPendingMinor($payment->id));
            if ($amount > 0) {
                $refundId = Refunds::recordPending($payment, $amount)->id;
            }
        }

        $document = Invoice::query()->where('order_id', $order->id)->whereNull('shipment_id')
            ->where('status', '<>', 'void')->orderBy('id')->first();
        $creditNote = $document === null ? null : CreditNote::query()->create([
            // 02 §11.3: the number series is locked last.
            'credit_note_number' => $this->numbers->next('credit_note_number'),
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'invoice_id' => $document->id,
            'reason' => 'cancellation',
            'currency' => $document->currency,
            'subtotal_net_minor' => $creditNet,
            'tax_minor' => $creditTax,
            'total_gross_minor' => $gross,
            'issued_at' => now(),
        ]);

        return [$refundId, $creditNote];
    }
}
