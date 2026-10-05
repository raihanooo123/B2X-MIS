<?php

namespace App\Domain\Ordering;

use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\Refunds;
use App\Domain\Billing\RefundSettlement;
use App\Domain\Collection\CollectionBookings;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\MovementAttribution;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Reference\NumberSequenceService;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationLine;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\StockAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 05.4 §13.2 — a consumer cancels their order before anything has been
 * dispatched. Whole order only (05.15 §12 Q6); a trade order is refused
 * (Q11). Not an RMA: there is nothing to send back.
 *
 * One transaction, retried whole on deadlock (04 §4.5), in the lock order
 * dispatch uses (DispatchService), so a cancel and a dispatch racing for
 * one order serialise on its row and exactly one wins:
 *
 *   1. orders FOR UPDATE — re-check that nothing is dispatched.
 *   1a. a collection order: its collection_slots row, then its booking
 *      (05.6 §7A.4); a live booking is cancelled and its slot released.
 *   2. shipments of the order FOR UPDATE; those not dispatched → cancelled.
 *   3. stock_allocations FOR UPDATE ascending id, then stock_levels in the
 *      02 §11.1 order — both inside DeallocationService, which writes the
 *      `deallocation` movements (reason `order_cancelled`).
 *   4. orders → cancelled.
 *   5. payments FOR UPDATE: an authorised card payment → voided (released
 *      after commit); a captured payment → a pending refund row (refunded
 *      after commit). An unpaid BACS order has nothing to refund.
 *   6. A receipt already issued → a credit note `cancellation` for its full
 *      gross, no company, no account movement (02 §14.5.3 as amended). Its
 *      number is taken last (02 §11.3).
 *
 * No gateway call inside the transaction (CLAUDE.md invariant 6). After
 * commit: release authorisations, refund captured card payments. A refund
 * the gateway refuses is marked failed and accounts are told
 * (`refund.failed`); a BACS refund stays pending for accounts to pay.
 */
final class OrderCancellationService
{
    public const MOVEMENT_REASON = 'order_cancelled';

    /** 05.6 §7A.5: `order_cancellations.reason_code` for the expiry sweep. */
    public const EXPIRED_REASON = 'collection_expired';

    private const CANCELLABLE_STATUSES = ['confirmed', 'picking'];

    public function __construct(
        private readonly DeallocationService $deallocation = new DeallocationService,
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
        private readonly CollectionBookings $bookings = new CollectionBookings,
    ) {}

    /**
     * @throws OrderNotCancellableException
     */
    /**
     * @param  string  $initiatedBy  `customer` (signed in or by guest link), or `staff` recording what the customer told us
     */
    public function cancel(int $orderId, ?int $actorUserId = null, string $initiatedBy = 'customer'): Order
    {
        /** @var array{release: list<string>, refunds: list<int>} $outcome */
        $outcome = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->cancelWithinTransaction($orderId, $actorUserId, $initiatedBy)),
            self::class,
        );

        $this->settle($outcome);

        return Order::query()->findOrFail($orderId);
    }

    /**
     * 05.6 §7A.5 step 3 — the expiry sweep cancels an unpaid pay-at-collection
     * order whose deadline has passed, inside the sweep's own transaction,
     * the `orders` and `collection_bookings` rows already locked and the
     * booking already `no_show`. Trade orders too (the sweep is not a
     * customer cancellation), and staged goods are released even if packed.
     * Nothing was paid, so nothing is refunded; the customer is told by
     * `collection.expired`, not `order.cancelled`.
     */
    public function cancelExpiredCollection(int $orderId): void
    {
        $this->cancelWithinTransaction($orderId, null, 'system', expiry: true);
    }

    /**
     * @return array{release: list<string>, refunds: list<int>}
     */
    private function cancelWithinTransaction(int $orderId, ?int $actorUserId, string $initiatedBy, bool $expiry = false): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        if ($expiry) {
            if (! in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
                throw new OrderNotCancellableException('not_cancellable', "Order {$order->order_number} is {$order->status}; it cannot expire.");
            }
        } else {
            $this->assertCancellable($order);
        }

        // 05.6 §7A.4, §7A.7: orders → collection_slots → collection_bookings,
        // before shipments. The booking is cancelled and its slot released.
        // On expiry the sweep holds the booking (now `no_show`) already and
        // the slot keeps its count, so no slot is locked (§7A.5).
        if ($order->fulfilment_type === 'collection' && ! $expiry) {
            $this->bookings->cancelLocked($this->bookings->lockForOrder($order->id));
        }

        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($shipments->contains(fn (Shipment $s) => $s->status === 'dispatched' || $s->dispatched_at !== null)) {
            throw new OrderNotCancellableException('order_already_dispatched', 'Part of this order has already been sent. You can cancel by returning it once it arrives.');
        }
        if (! $expiry && $shipments->contains(fn (Shipment $s) => $s->status === 'packed')) {
            throw new OrderNotCancellableException('line_packed', 'This order is already packed. Please contact us to unpack it before cancelling.');
        }
        Shipment::query()->whereIn('id', $shipments->pluck('id'))->update(['status' => 'cancelled', 'updated_at' => now()]);

        $allocationIds = StockAllocation::query()
            ->whereIn('order_line_id', OrderLine::query()->where('order_id', $order->id)->select('id'))
            ->whereIn('status', ['allocated', 'picked'])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($allocationIds !== []) {
            $this->deallocation->deallocateWithinTransaction(
                array_values($allocationIds),
                new MovementAttribution($actorUserId, self::MOVEMENT_REASON, $expiry
                    ? "Order {$order->order_number} not collected and paid by its deadline (05.6 §7A.5)"
                    : "Order {$order->order_number} cancelled before dispatch (05.4 §13.2)"),
            );
        }

        $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_fee_minor' => 0]);

        $release = [];
        $refunds = [];
        $payments = Payment::query()->where('order_id', $order->id)->where('type', 'payment')->orderBy('id')->lockForUpdate()->get();
        foreach ($payments as $payment) {
            if ($payment->status === 'authorized' && $payment->gateway === 'stripe' && $payment->gateway_reference !== null) {
                // Voided now, so a late capture webhook cannot mark a cancelled order paid.
                $payment->update(['status' => 'voided']);
                $release[] = $payment->gateway_reference;
            } elseif (in_array($payment->status, ['captured', 'part_refunded'], true)) {
                // part_refunded: an earlier partial cancellation refunded some of it (05.10 §2).
                $due = $payment->amount_minor - Refunds::refundedOrPendingMinor($payment->id);
                if ($due > 0) {
                    $refunds[] = Refunds::recordPending($payment, $due)->id;
                }
            }
        }

        // 05.10 §2: what is left of each line, after any earlier partial cancellation.
        $cancelledNet = 0;
        $cancelledTax = 0;
        $cancelledLines = [];
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $remaining = $line->base_qty - $line->dispatched_base_qty - $line->cancelled_base_qty;
            if ($remaining <= 0) {
                continue;
            }
            $net = PartialCancellations::billable($line->line_net_minor, $line->cancelled_base_qty, $line->base_qty);
            $tax = PartialCancellations::billable($line->line_tax_minor, $line->cancelled_base_qty, $line->base_qty);
            $cancelledNet += $net;
            $cancelledTax += $tax;
            $cancelledLines[] = [$line->id, intdiv($remaining, $line->pack_base_units), $remaining, $net, $tax];
            $line->forceFill(['cancelled_base_qty' => $line->cancelled_base_qty + $remaining])->save();
        }

        $creditNoteId = null;
        $receipts = Invoice::query()->where('order_id', $order->id)->whereNull('company_id')->where('status', '<>', 'void')->orderBy('id')->get();
        foreach ($receipts as $receipt) {
            // Only what earlier cancellation credit notes have not already credited.
            $credited = (int) CreditNote::query()->where('invoice_id', $receipt->id)->where('status', '<>', 'void')->sum('total_gross_minor');
            $creditedTax = (int) CreditNote::query()->where('invoice_id', $receipt->id)->where('status', '<>', 'void')->sum('tax_minor');
            $gross = $receipt->total_gross_minor - $credited;
            if ($gross <= 0) {
                continue;
            }
            $creditNoteId ??= CreditNote::query()->create([
                'credit_note_number' => $this->numbers->next('credit_note_number'),
                'order_id' => $order->id,
                'invoice_id' => $receipt->id,
                'reason' => 'cancellation',
                'currency' => $receipt->currency,
                'subtotal_net_minor' => $gross - ($receipt->tax_minor - $creditedTax),
                'tax_minor' => $receipt->tax_minor - $creditedTax,
                'total_gross_minor' => $gross,
                'issued_at' => now(),
            ])->id;
        }

        // 05.10 §2.3: every cancellation has one record, whole or part.
        $cancellation = OrderCancellation::query()->create([
            'order_id' => $order->id,
            'kind' => 'whole',
            'initiated_by' => $initiatedBy,
            'actor_user_id' => $actorUserId,
            'customer_notified_at' => now(),
            'reason_code' => $expiry ? self::EXPIRED_REASON : null,
            'cancelled_net_minor' => $cancelledNet,
            'cancelled_tax_minor' => $cancelledTax,
            'cancelled_gross_minor' => $cancelledNet + $cancelledTax,
            'delivery_refund_net_minor' => $order->shipping_net_minor,
            'delivery_refund_tax_minor' => $order->shipping_tax_minor,
            'credit_note_id' => $creditNoteId,
            'refund_payment_id' => $refunds[0] ?? null,
        ]);
        foreach ($cancelledLines as [$lineId, $packQty, $baseQty, $net, $tax]) {
            OrderCancellationLine::query()->create([
                'order_cancellation_id' => $cancellation->id,
                'order_line_id' => $lineId,
                'cancelled_pack_qty' => $packQty,
                'cancelled_base_qty' => $baseQty,
                'line_net_minor' => $net,
                'line_tax_minor' => $tax,
                'line_gross_minor' => $net + $tax,
            ]);
        }

        if (! $expiry) {
            $this->notifications->orderCancelled($order->id);
        }

        return ['release' => $release, 'refunds' => $refunds];
    }

    private function assertCancellable(Order $order): void
    {
        if ($order->company_id !== null) {
            throw new OrderNotCancellableException('not_consumer_order', 'Trade orders are changed by contacting us.');
        }
        // 05.4 §14.2 R13: performance of the original contract, not a new sale.
        if ($order->order_kind === OrderKind::Replacement->value) {
            throw new OrderNotCancellableException('replacement_order', 'This is a replacement for faulty goods. Please contact us if you no longer want it.');
        }
        if ($order->status === 'cancelled') {
            throw new OrderNotCancellableException('already_cancelled', 'This order is already cancelled.');
        }
        if (in_array($order->status, ['part_dispatched', 'dispatched', 'completed'], true)) {
            throw new OrderNotCancellableException('order_already_dispatched', 'This order has already been sent. You can cancel by returning it once it arrives.');
        }
        if (! in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
            throw new OrderNotCancellableException('not_cancellable', 'This order cannot be cancelled online. Please contact us.');
        }
    }

    /**
     * After commit: the gateway calls the transaction must never make.
     *
     * @param  array{release: list<string>, refunds: list<int>}  $outcome
     */
    private function settle(array $outcome): void
    {
        foreach ($outcome['release'] as $intentId) {
            try {
                $this->gateway()->cancel($intentId);
            } catch (PaymentGatewayException $e) {
                // The authorisation lapses on its own (Stripe: 7 days). Reconciliation
                // (billing:reconcile-card-payments) reports one captured meanwhile.
                Log::warning('Could not release the card authorisation of a cancelled order.', ['payment_intent' => $intentId, 'error' => $e->getMessage()]);
            }
        }

        (new RefundSettlement($this->notifications))->settle($outcome['refunds']);
    }

    /** Resolved on use, so a cancellation with no card payment never builds a Stripe client. */
    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }
}
