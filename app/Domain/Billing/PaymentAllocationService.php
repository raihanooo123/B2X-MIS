<?php

namespace App\Domain\Billing;

use App\Domain\Ordering\PaymentMethod;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;

/**
 * Cash application (02 §14.5.4): a captured payment settles its order's
 * invoice. A card payment is usually captured before its invoice exists
 * (InvoiceService issues it at capture, after commit), so it sits
 * **unallocated** (no `payment_allocations` row) until the invoice is
 * issued, when InvoiceService calls allocateInvoice().
 *
 * Both entry points apply the same rule: allocate the smaller of what the
 * payment has left and what the invoice still owes, one allocation per
 * (payment, invoice) pair — `allocation_reference` is unique
 * (`payment_allocations_reference_uq`), so a repeat call never allocates
 * twice. `invoices.paid_minor` and `status` are maintained in the same
 * transaction (§11.4: projections are written with their source).
 *
 * Locks follow 02 §11.1 then §14.5.4: `companies` (only when credit
 * moves), then the payment, then the invoice.
 *
 * On-account invoices carry credit: paying one releases `credit_used_minor`
 * by the amount applied (05.2 §8.3 "Invoice paid"), in the same
 * transaction. Prepaid invoices never entered `credit_used_minor`.
 *
 * A public customer's receipt (§21.2) is allocated like any invoice, so
 * its `paid_minor` stays a true projection; with no company, no credit
 * moves.
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
        $creditCompanyId = $this->creditCompanyId($invoiceId);

        DB::transaction(function () use ($paymentId, $invoiceId, $creditCompanyId) {
            if ($creditCompanyId !== null) {
                Company::query()->whereKey($creditCompanyId)->lockForUpdate()->first(['id']);
            }
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
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

            if ($creditCompanyId !== null) {
                Company::query()->whereKey($creditCompanyId)->decrement('credit_used_minor', $amount);
            }
        });
    }

    /**
     * The company whose `credit_used_minor` this invoice counts in — set
     * only for an on-account order's invoice. Read before the transaction
     * so `companies` can be locked first; neither column ever changes on
     * an issued invoice or placed order.
     */
    private function creditCompanyId(int $invoiceId): ?int
    {
        $row = Invoice::query()
            ->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->where('invoices.id', $invoiceId)
            ->first(['invoices.company_id', 'orders.payment_method']);

        if ($row === null || $row->company_id === null || $row->getAttribute('payment_method') !== PaymentMethod::OnAccount->value) {
            return null;
        }

        return (int) $row->company_id;
    }
}
