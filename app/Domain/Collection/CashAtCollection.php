<?php

namespace App\Domain\Collection;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PaymentAllocationService;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Ordering\OrderPayments;
use App\Domain\Ordering\PaymentMethod;
use App\Models\CollectionBooking;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 05.6 §7A.6 steps 2–3 — at the counter, staff take a pay-at-collection
 * order's amount in cash, then its receipt (public) or VAT invoice (trade,
 * terms `prepay`) is issued.
 *
 * record(): one transaction, `orders` → `collection_bookings` FOR UPDATE
 * (§7A.4), re-checking that the order is unpaid, booked and not expired;
 * then a `payments` row (`gateway = 'cash'`, `captured`, the amount due,
 * `recorded_by_user_id`), the order `paid` (OrderPayments::markPaid) and
 * the audit row, together. A second click waits on the order lock and finds
 * the order paid: it is answered with the first payment, never a second
 * (`payments_cash_order_uq` is the backstop). The expiry sweep locks the
 * same order row first, so a payment and the sweep serialise: whoever
 * commits first wins (§7A.5).
 *
 * After commit, synchronously and in its own transaction (`companies` →
 * `orders` → number series, as for card capture), the receipt or invoice
 * is issued and allocated the cash payment (02 §14.5.4). A failure to issue
 * never undoes the payment: it is logged, and `billing:issue-missing-invoices`
 * finds the order.
 *
 * void(): before handover only, by `accounts`. The payment → `voided`, the
 * order back to `unpaid`, and any allocation reversed by a negative
 * allocation (`reason_code = 'cash_voided'`) so the receipt's `paid_minor`
 * stays true; audited `payment.cash_voided` with the reason. The amount can
 * then be recorded again.
 */
final class CashAtCollection
{
    public const VOID_ALLOCATION_REASON = 'cash_voided';

    public function __construct(
        private readonly InvoiceService $invoices = new InvoiceService,
        private readonly PaymentAllocationService $allocations = new PaymentAllocationService,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * §7A.9: the placed total, less anything cancelled since (05.10 §2) —
     * "the amount due falls by exactly the cancelled share".
     */
    public static function amountDueMinor(Order $order): int
    {
        $cancelled = (int) OrderCancellation::query()->where('order_id', $order->id)
            ->sum(DB::raw('cancelled_gross_minor + delivery_refund_net_minor + delivery_refund_tax_minor'));

        return max(0, $order->total_gross_minor - $cancelled);
    }

    /**
     * @throws CashRefused
     */
    public function record(int $orderId, int $staffUserId, int $confirmedAmountMinor): CashRecorded
    {
        [$payment, $replayed] = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->recordWithinTransaction($orderId, $staffUserId, $confirmedAmountMinor)),
            self::class,
        );

        return new CashRecorded($payment, $replayed, $this->issueDocument($payment));
    }

    /**
     * @throws CashRefused
     */
    public function void(int $paymentId, int $staffUserId, string $reason): Payment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new CashRefused('reason_required', 'Give a reason for voiding this cash payment.');
        }

        return (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->voidWithinTransaction($paymentId, $staffUserId, $reason)),
            self::class,
        );
    }

    /**
     * @return array{0: Payment, 1: bool}
     */
    private function recordWithinTransaction(int $orderId, int $staffUserId, int $confirmedAmountMinor): array
    {
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->firstOrFail();
        $booking = CollectionBooking::query()->where('order_id', $orderId)->lockForUpdate()->first();

        if ($order->payment_method !== PaymentMethod::CashAtCollection->value || $booking === null) {
            throw new CashRefused('not_pay_at_collection', "Order {$order->order_number} is not paid in cash at collection.");
        }
        if ($order->payment_status === 'paid') {
            $existing = Payment::query()->where('order_id', $orderId)->where('gateway', 'cash')
                ->where('type', 'payment')->where('status', 'captured')->first();
            if ($existing !== null) {
                return [$existing, true];
            }
        }
        if ($order->status === 'cancelled' || $booking->status === 'no_show') {
            throw new CashRefused('order_expired', "Order {$order->order_number} expired and its stock was released. It can no longer be paid for; place a new order if the stock is still there.", 409);
        }
        if ($booking->status !== 'booked' || $order->payment_status !== 'unpaid') {
            throw new CashRefused('not_payable', "Order {$order->order_number} cannot take a cash payment now.", 409);
        }

        $amount = self::amountDueMinor($order);
        if ($confirmedAmountMinor !== $amount) {
            throw new CashRefused('amount_mismatch', 'The amount confirmed does not match the amount due. Check the order and try again.', 409);
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'company_id' => $order->company_id,
            'type' => 'payment',
            'gateway' => 'cash',
            'status' => 'captured',
            'amount_minor' => $amount,
            'currency' => $order->currency,
            'recorded_by_user_id' => $staffUserId,
            'captured_at' => now(),
        ]);
        OrderPayments::markPaid($order->id);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentCashRecorded,
            actorType: 'user',
            actorUserId: $staffUserId,
            companyId: $order->company_id,
            subjectType: 'payment',
            subjectId: $payment->id,
            after: ['order_id' => $order->id, 'amount_minor' => $amount],
        ));

        return [$payment, false];
    }

    private function voidWithinTransaction(int $paymentId, int $staffUserId, string $reason): Payment
    {
        $orderId = Payment::query()->where('id', $paymentId)->value('order_id')
            ?? throw new CashRefused('not_found', 'Cash payment not found.', 404);

        // orders → collection_bookings → payments (§7A.4).
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->firstOrFail();
        $booking = CollectionBooking::query()->where('order_id', $orderId)->lockForUpdate()->first();
        $payment = Payment::query()->where('id', $paymentId)->lockForUpdate()->firstOrFail();

        if ($payment->gateway !== 'cash' || $payment->type !== 'payment' || $payment->status !== 'captured') {
            throw new CashRefused('not_voidable', 'Only a recorded cash payment can be voided.', 409);
        }
        // After handover, a mistake is a refund, never an edit.
        if ($booking === null || $booking->status !== 'booked' || ! in_array($order->status, ['confirmed', 'picking'], true)) {
            throw new CashRefused('already_handed_over', 'This order has been handed over. Correct it with a refund instead.', 409);
        }

        $payment->forceFill(['status' => 'voided'])->save();
        Order::query()->where('id', $order->id)->where('payment_status', 'paid')
            ->update(['payment_status' => 'unpaid', 'updated_at' => now()]);
        $this->reverseAllocations($payment, $staffUserId);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentCashVoided,
            actorType: 'user',
            actorUserId: $staffUserId,
            companyId: $order->company_id,
            subjectType: 'payment',
            subjectId: $payment->id,
            before: ['status' => 'captured'],
            after: ['status' => 'voided'],
            reason: $reason,
        ));

        return $payment;
    }

    /**
     * The receipt keeps a true `paid_minor` (02 §14.5.4): what the voided
     * payment was allocated is reversed by a negative allocation, never by
     * editing or deleting the original. Payment, then invoice (§14.5.4).
     */
    private function reverseAllocations(Payment $payment, int $staffUserId): void
    {
        $byInvoice = PaymentAllocation::query()->where('payment_id', $payment->id)
            ->groupBy('invoice_id')->orderBy('invoice_id')
            ->selectRaw('invoice_id, sum(amount_minor) AS allocated')
            ->pluck('allocated', 'invoice_id');

        foreach ($byInvoice as $invoiceId => $allocated) {
            $allocated = (int) $allocated;
            if ($allocated <= 0) {
                continue;
            }
            $invoice = Invoice::query()->where('id', $invoiceId)->lockForUpdate()->firstOrFail();
            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_minor' => -$allocated,
                'allocation_reference' => "payment:{$payment->id}:invoice:{$invoice->id}:void",
                'reason_code' => self::VOID_ALLOCATION_REASON,
                'actor_user_id' => $staffUserId,
            ]);
            $paid = max(0, (int) $invoice->paid_minor - $allocated);
            $settled = $paid + PaymentAllocationService::cancellationCreditMinor($invoice->id) >= (int) $invoice->total_gross_minor;
            $invoice->update(['paid_minor' => $paid, 'status' => match (true) {
                $paid === 0 => 'issued',
                $settled => 'paid',
                default => 'part_paid',
            }]);
        }
    }

    /** After commit, synchronously (§7A.6 step 3). */
    private function issueDocument(Payment $payment): ?Invoice
    {
        try {
            $invoice = $this->invoices->issueForOrder((int) $payment->order_id);
            // A re-recorded payment meets the document issued for the first one.
            $this->allocations->allocatePayment($payment->id);

            return $invoice->fresh();
        } catch (Throwable $e) {
            Log::error('Cash recorded but no receipt or invoice issued; run billing:issue-missing-invoices once the cause is fixed.', [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
