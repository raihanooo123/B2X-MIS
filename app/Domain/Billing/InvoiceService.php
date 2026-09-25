<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Events\InvoiceIssued;
use App\Domain\Billing\Exceptions\SellerVatNumberMissingException;
use App\Domain\Ordering\PaymentMethod;
use App\Domain\Reference\NumberSequenceService;
use App\Jobs\ArchiveInvoicePdf;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Order;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Issues invoices and receipts (02 §14.5.2, §21.2; 05.5 §7.3).
 *
 * When each order is documented (05.5 §7.3 as amended 2026-09-25):
 *
 *   - card       — at capture (whenPaid), whole order.
 *   - BACS, prepay (trade) — at placement (whenPlaced), whole order.
 *   - on account — at dispatch. The dispatch flow does not exist yet
 *                  (`shipments`, 02 §14.6), so nothing calls
 *                  issueForOrder() for these orders today. Per-shipment
 *                  invoicing (`invoicing.mode = per_shipment`) needs
 *                  `shipment_lines` to derive lines and lands with it.
 *   - public customers — a receipt (no company, no terms, no due date),
 *                  only once paid: card at capture. A public BACS or
 *                  prepay receipt waits for payment recording.
 *
 * issueForOrder() is one transaction, locks in the global order (02
 * §11.1): `companies` (on-account only, for the credit conversion), then
 * the `orders` row (serialising two issuers of the same order), then the
 * number series — taken last, per §11.3. An order has at most one live
 * whole-order document; a second call returns it.
 *
 * On account, the same transaction converts the credit hold (05.2 §8.3):
 * hold → `invoiced`, `credit_held_minor` −= hold, `credit_used_minor` +=
 * invoice total. Prepaid orders took no hold and touch no credit.
 *
 * Payment terms and due date are snapshotted at issue (§14.5.2): a
 * company's terms changing later never moves an issued due date. A
 * prepaid trade invoice is `prepay`, due on issue.
 *
 * After commit: captured payments are applied (PaymentAllocationService),
 * the PDF is queued for rendering and archiving (02 §21.1, 07 P25), and
 * InvoiceIssued fires. Nothing external runs inside the transaction.
 */
final class InvoiceService
{
    public const INVOICE_SEQUENCE = 'invoice_number';

    public const RECEIPT_SEQUENCE = 'receipt_number';

    /** Days from issue to due, per `payment_terms` (05.2 §9). */
    private const TERM_DAYS = [
        'prepay' => 0,
        'net7' => 7,
        'net14' => 14,
        'net30' => 30,
        'net60' => 60,
    ];

    /** Orders in these states are not billable. */
    private const UNBILLABLE_STATUSES = ['draft', 'awaiting_approval', 'cancelled'];

    public function __construct(
        private readonly NumberSequenceService $numberSequenceService = new NumberSequenceService,
        private readonly PaymentAllocationService $paymentAllocationService = new PaymentAllocationService,
    ) {}

    /**
     * Issue the whole-order invoice (or, for a public customer, receipt).
     * Idempotent: returns the order's existing live document if it has
     * one.
     *
     * @throws SellerVatNumberMissingException when issuing a VAT invoice without `seller.vat_number`
     */
    public function issueForOrder(int $orderId): Invoice
    {
        $order = Order::query()->findOrFail($orderId, ['id', 'company_id', 'payment_method']);
        $companyId = $order->company_id;
        $onAccount = $companyId !== null && $order->payment_method === PaymentMethod::OnAccount->value;

        return DB::transaction(function () use ($orderId, $companyId, $onAccount) {
            $company = null;
            if ($companyId !== null) {
                // 02 §11.1: companies first. Locked only when credit moves.
                $company = Company::query()->whereKey($companyId)
                    ->when($onAccount, fn ($q) => $q->lockForUpdate())
                    ->firstOrFail(['id', 'payment_terms']);
            }

            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            $existing = $this->liveWholeOrderDocument($orderId);
            if ($existing !== null) {
                return $existing;
            }

            if (in_array($order->status, self::UNBILLABLE_STATUSES, true)) {
                throw new LogicException("Order {$orderId} is '{$order->status}' and cannot be invoiced.");
            }

            // Checked before a number is taken: an invalid VAT invoice is
            // never issued, and a refused one consumes nothing.
            if ($company !== null && SellerDetails::fromConfiguration()->vatNumber === null) {
                throw new SellerVatNumberMissingException;
            }

            $issuedAt = now();
            $paymentTerms = null;
            $dueAt = null;
            if ($company !== null) {
                $paymentTerms = $onAccount ? $company->payment_terms : 'prepay';
                $dueAt = $issuedAt->copy()->addDays(self::TERM_DAYS[$paymentTerms]);
            }

            // 02 §11.3: the number series is locked last.
            $number = $this->numberSequenceService->next($company === null ? self::RECEIPT_SEQUENCE : self::INVOICE_SEQUENCE);

            $invoice = Invoice::query()->create([
                'invoice_number' => $number,
                'company_id' => $companyId,
                'order_id' => $orderId,
                'shipment_id' => null,
                'status' => 'issued',
                'currency' => $order->currency,
                // Snapshotted from the order, itself a snapshot (invariant 4).
                // subtotal is Σ line_net (after every discount); the
                // discount column records what was taken off, for display.
                'subtotal_net_minor' => $order->subtotal_net_minor,
                'discount_net_minor' => (int) $order->discount_net_minor + $order->spend_break_discount_minor,
                'shipping_net_minor' => $order->shipping_net_minor,
                'tax_minor' => $order->tax_minor,
                'total_gross_minor' => $order->total_gross_minor,
                'paid_minor' => 0,
                'payment_terms' => $paymentTerms,
                'due_at' => $dueAt,
                'issued_at' => $issuedAt,
            ]);

            if ($onAccount) {
                $this->convertCreditHold($company->id, $orderId, $invoice);
            }

            $invoiceId = (int) $invoice->id;
            DB::afterCommit(function () use ($invoiceId, $orderId) {
                $this->paymentAllocationService->allocateInvoice($invoiceId);
                ArchiveInvoicePdf::dispatch($invoiceId);
                event(new InvoiceIssued($invoiceId, $orderId));
            });

            return $invoice;
        });
    }

    /**
     * 05.5 §7.3: a trade BACS or prepay order is invoiced when placed.
     * Call from the checkout transaction; issues once it commits.
     */
    public function whenPlaced(int $orderId): void
    {
        $this->issueAfterCommit($orderId, 'placed', fn (Order $order) => $order->company_id !== null
            && in_array($order->payment_method, [PaymentMethod::Bacs->value, PaymentMethod::Prepay->value], true));
    }

    /**
     * 05.5 §7.3: a prepaid order is invoiced — a public order receipted —
     * when its payment is captured. An order already invoiced at
     * placement is left as it is.
     */
    public function whenPaid(int $orderId): void
    {
        $this->issueAfterCommit($orderId, 'paid', fn (Order $order) => $order->payment_method !== PaymentMethod::OnAccount->value);
    }

    /**
     * The order and its payment are already committed and correct, so a
     * failure to issue (no seller VAT number, no number series) must not
     * undo them. It is logged, and `billing:issue-missing-invoices` finds
     * the order afterwards.
     *
     * @param  Closure(Order): bool  $applies
     */
    private function issueAfterCommit(int $orderId, string $trigger, Closure $applies): void
    {
        DB::afterCommit(function () use ($orderId, $trigger, $applies) {
            try {
                $order = Order::query()->find($orderId, ['id', 'company_id', 'payment_method']);
                if ($order !== null && $applies($order)) {
                    $this->issueForOrder($orderId);
                }
            } catch (Throwable $e) {
                Log::error('Invoice not issued; run billing:issue-missing-invoices once the cause is fixed.', [
                    'order_id' => $orderId,
                    'trigger' => $trigger,
                    'exception' => $e,
                ]);
            }
        });
    }

    private function liveWholeOrderDocument(int $orderId): ?Invoice
    {
        return Invoice::query()
            ->where('order_id', $orderId)
            ->whereNull('shipment_id')
            ->where('status', '<>', 'void')
            ->first();
    }

    /**
     * 05.2 §8.3 "Order invoiced". The company row is already locked by the
     * caller. An order with no live hold (placed before `credit_holds`
     * existed) still moves the invoice total into `credit_used_minor`.
     */
    private function convertCreditHold(int $companyId, int $orderId, Invoice $invoice): void
    {
        $hold = CreditHold::query()
            ->where('order_id', $orderId)
            ->where('status', 'held')
            ->lockForUpdate()
            ->first();

        if ($hold !== null) {
            $hold->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);
            Company::query()->whereKey($companyId)->decrement('credit_held_minor', $hold->amount_minor);
        }

        Company::query()->whereKey($companyId)->increment('credit_used_minor', $invoice->total_gross_minor);
    }
}
