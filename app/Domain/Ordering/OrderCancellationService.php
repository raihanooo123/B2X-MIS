<?php

namespace App\Domain\Ordering;

use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\Refunds;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\MovementAttribution;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Reference\NumberSequenceService;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
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

    private const CANCELLABLE_STATUSES = ['confirmed', 'picking'];

    public function __construct(
        private readonly DeallocationService $deallocation = new DeallocationService,
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /**
     * @throws OrderNotCancellableException
     */
    public function cancel(int $orderId, ?int $actorUserId = null): Order
    {
        /** @var array{release: list<string>, refunds: list<int>} $outcome */
        $outcome = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->cancelWithinTransaction($orderId, $actorUserId)),
            self::class,
        );

        $this->settle($outcome);

        return Order::query()->findOrFail($orderId);
    }

    /**
     * @return array{release: list<string>, refunds: list<int>}
     */
    private function cancelWithinTransaction(int $orderId, ?int $actorUserId): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $this->assertCancellable($order);

        $shipments = Shipment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($shipments->contains(fn (Shipment $s) => $s->status === 'dispatched' || $s->dispatched_at !== null)) {
            throw new OrderNotCancellableException('order_already_dispatched', 'Part of this order has already been sent. You can cancel by returning it once it arrives.');
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
                new MovementAttribution($actorUserId, self::MOVEMENT_REASON, "Order {$order->order_number} cancelled before dispatch (05.4 §13.2)"),
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
            } elseif ($payment->status === 'captured') {
                $due = $payment->amount_minor - Refunds::refundedOrPendingMinor($payment->id);
                if ($due > 0) {
                    $refunds[] = Refunds::recordPending($payment, $due)->id;
                }
            }
        }

        $receipts = Invoice::query()->where('order_id', $order->id)->whereNull('company_id')->where('status', '<>', 'void')->orderBy('id')->get();
        foreach ($receipts as $receipt) {
            CreditNote::query()->create([
                'credit_note_number' => $this->numbers->next('credit_note_number'),
                'order_id' => $order->id,
                'invoice_id' => $receipt->id,
                'reason' => 'cancellation',
                'currency' => $receipt->currency,
                'subtotal_net_minor' => $receipt->total_gross_minor - $receipt->tax_minor,
                'tax_minor' => $receipt->tax_minor,
                'total_gross_minor' => $receipt->total_gross_minor,
                'issued_at' => now(),
            ]);
        }

        $this->notifications->orderCancelled($order->id);

        return ['release' => $release, 'refunds' => $refunds];
    }

    private function assertCancellable(Order $order): void
    {
        if ($order->company_id !== null) {
            throw new OrderNotCancellableException('not_consumer_order', 'Trade orders are changed by contacting us.');
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

        foreach ($outcome['refunds'] as $refundId) {
            $refund = Payment::query()->find($refundId);
            $original = $refund?->refunded_payment_id === null ? null : Payment::query()->find($refund->refunded_payment_id);
            if ($refund === null || $original === null || $refund->gateway !== 'stripe' || $original->gateway_reference === null) {
                continue; // BACS: accounts repay by transfer and record it.
            }

            try {
                $reference = $this->gateway()->refund($original->gateway_reference, $refund->amount_minor, 'refund:'.$refund->public_id);
                Refunds::markSucceeded($refund->id, $reference);
            } catch (PaymentGatewayException $e) {
                Refunds::markFailed($refund->id, $e->getMessage());
                Log::error('Card refund failed for a cancelled order; accounts notified.', ['refund' => $refund->id, 'error' => $e->getMessage()]);
                $this->notifications->refundFailed($refund->id);
            }
        }
    }

    /** Resolved on use, so a cancellation with no card payment never builds a Stripe client. */
    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }
}
