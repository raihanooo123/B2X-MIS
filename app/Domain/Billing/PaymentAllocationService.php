<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;

/**
 * Cash application (02 §14.5.4): a captured payment settles its order's
 * invoice — but invoices are issued later (per shipment, 05.5 §7.3), so a
 * card payment taken at checkout usually has no invoice to settle yet. It
 * then sits **unallocated** (no `payment_allocations` row) until one is
 * issued, when invoice issuance calls allocateInvoice().
 *
 * Both entry points apply the same rule: allocate the smaller of what the
 * payment has left and what the invoice still owes, one allocation per
 * (payment, invoice) pair — `allocation_reference` is unique
 * (`payment_allocations_reference_uq`), so a repeat call never allocates
 * twice. `invoices.paid_minor` and `status` are maintained in the same
 * transaction, the invoice row locked first (§11.4: projections are
 * written with their source).
 *
 * Public customers' orders are not invoiced (`invoices.company_id` is NOT
 * NULL, §19), so their card payments stay unallocated — correctly: there
 * is no account balance to settle.
 */
final class PaymentAllocationService
{
    /** After a payment is captured: settle any invoice its order already has. */
    public function allocatePayment(int $paymentId): void
    {
        $payment = Payment::query()->find($paymentId);
        if ($payment === null || $payment->status !== 'captured' || $payment->order_id === null) {
            return;
        }

        $invoiceIds = Invoice::query()->where('order_id', $payment->order_id)->orderBy('id')->pluck('id');
        foreach ($invoiceIds as $invoiceId) {
            $this->allocate((int) $paymentId, (int) $invoiceId);
        }
    }

    /** When an invoice is issued: apply its order's unallocated captured payments. */
    public function allocateInvoice(int $invoiceId): void
    {
        $orderId = Invoice::query()->whereKey($invoiceId)->value('order_id');
        if ($orderId === null) {
            return;
        }

        $paymentIds = Payment::query()
            ->where('order_id', $orderId)
            ->where('type', 'payment')
            ->where('status', 'captured')
            ->orderBy('id')
            ->pluck('id');

        foreach ($paymentIds as $paymentId) {
            $this->allocate((int) $paymentId, $invoiceId);
        }
    }

    private function allocate(int $paymentId, int $invoiceId): void
    {
        DB::transaction(function () use ($paymentId, $invoiceId) {
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();
            if ($invoice === null || $payment === null || in_array($invoice->status, ['void', 'credited'], true)) {
                return;
            }

            $reference = "payment:{$paymentId}:invoice:{$invoiceId}";
            if (PaymentAllocation::query()->where('allocation_reference', $reference)->exists()) {
                return;
            }

            $allocated = (int) PaymentAllocation::query()->where('payment_id', $paymentId)->sum('amount_minor');
            $available = (int) $payment->amount_minor - $allocated;
            $owed = (int) $invoice->total_gross_minor - (int) $invoice->paid_minor;
            $amount = min($available, $owed);

            if ($amount <= 0) {
                return;
            }

            PaymentAllocation::query()->create([
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'amount_minor' => $amount,
                'allocation_reference' => $reference,
            ]);

            $paid = (int) $invoice->paid_minor + $amount;
            $invoice->update([
                'paid_minor' => $paid,
                'status' => $paid >= (int) $invoice->total_gross_minor ? 'paid' : 'part_paid',
            ]);
        });
    }
}
