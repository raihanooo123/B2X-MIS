<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Events\InvoiceIssued;
use App\Domain\Billing\Exceptions\SellerVatNumberMissingException;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\PaymentMethod;
use App\Domain\Reference\NumberSequenceService;
use App\Jobs\ArchiveInvoicePdf;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\SystemConfiguration;
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
 *   - on account — at dispatch (whenDispatched), per `invoicing.mode`:
 *                  `per_shipment` (the default) invoices each shipment
 *                  (issueForShipment); `on_completion` invoices the whole
 *                  order once its last shipment leaves.
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
 * `credit_used_minor` += invoice total, and the hold gives up what the
 * invoice consumes. A whole-order invoice consumes the hold (→ `invoiced`).
 * A per-shipment invoice reduces it by its total, and the invoice that
 * completes the order consumes what is left (05.5 §7.3 as amended
 * 2026-09-25). Prepaid orders took no hold and touch no credit.
 *
 * Per-shipment amounts are ShipmentInvoiceShares: pro-rata by base
 * quantity, remainder to the shipment completing each line, so a fully
 * dispatched order's invoices sum to its totals exactly. Carriage goes on
 * the order's first invoice.
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

    /** 05.5 §7.3: `system_configurations`, company then global. */
    public const MODE_KEY = 'invoicing.mode';

    public const MODE_PER_SHIPMENT = 'per_shipment';

    public const MODE_ON_COMPLETION = 'on_completion';

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
        private readonly Notifications $notifications = new Notifications,
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
                $this->convertCreditHold($company->id, $orderId, $invoice, true);
            }

            $this->afterIssue((int) $invoice->id, $orderId);

            return $invoice;
        });
    }

    /**
     * Issue the invoice for one dispatched shipment of an on-account
     * order. Idempotent: returns the shipment's live invoice if it has one.
     * Returns null when the order already carries a whole-order document,
     * so it is never billed twice.
     *
     * One transaction, same lock order as issueForOrder(): companies, the
     * order, the number series last.
     *
     * @throws SellerVatNumberMissingException
     */
    public function issueForShipment(int $shipmentId): ?Invoice
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);
        $order = Order::query()->findOrFail($shipment->order_id, ['id', 'company_id', 'payment_method']);
        $companyId = $order->company_id;

        if ($companyId === null || $order->payment_method !== PaymentMethod::OnAccount->value) {
            throw new LogicException("Shipment {$shipmentId}: only on-account orders are invoiced per shipment (05.5 §7.3).");
        }

        return DB::transaction(function () use ($shipment, $companyId) {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail(['id', 'payment_terms']);
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();

            $existing = Invoice::query()->where('shipment_id', $shipment->id)->where('status', '<>', 'void')->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($this->liveWholeOrderDocument($order->id) !== null) {
                return null;
            }
            if (in_array($order->status, self::UNBILLABLE_STATUSES, true)) {
                throw new LogicException("Order {$order->id} is '{$order->status}' and cannot be invoiced.");
            }
            if (SellerDetails::fromConfiguration()->vatNumber === null) {
                throw new SellerVatNumberMissingException;
            }

            $shares = (new ShipmentInvoiceShares)->forShipment($shipment);
            $subtotal = array_sum(array_map(fn (ShipmentLineShare $l) => $l->netMinor, $shares));
            $discount = array_sum(array_map(fn (ShipmentLineShare $l) => $l->discountMinor, $shares));
            $lineTax = array_sum(array_map(fn (ShipmentLineShare $l) => $l->taxMinor, $shares));

            // Carriage is charged once, on the order's first invoice.
            $first = ! Invoice::query()->where('order_id', $order->id)->where('status', '<>', 'void')->exists();
            $shipping = $first ? $order->shipping_net_minor : 0;
            $tax = $lineTax + ($first ? $order->shipping_tax_minor : 0);

            $issuedAt = now();
            $paymentTerms = $company->payment_terms;

            $invoice = Invoice::query()->create([
                'invoice_number' => $this->numberSequenceService->next(self::INVOICE_SEQUENCE),
                'company_id' => $company->id,
                'order_id' => $order->id,
                'shipment_id' => $shipment->id,
                'status' => 'issued',
                'currency' => $order->currency,
                'subtotal_net_minor' => $subtotal,
                'discount_net_minor' => $discount,
                'shipping_net_minor' => $shipping,
                'tax_minor' => $tax,
                'total_gross_minor' => $subtotal + $shipping + $tax,
                'paid_minor' => 0,
                'payment_terms' => $paymentTerms,
                'due_at' => $issuedAt->copy()->addDays(self::TERM_DAYS[$paymentTerms]),
                'issued_at' => $issuedAt,
            ]);

            $this->convertCreditHold($company->id, $order->id, $invoice, $order->status === 'dispatched');
            $this->afterIssue((int) $invoice->id, $order->id);

            return $invoice;
        });
    }

    /**
     * 05.5 §7.3: an on-account order is invoiced at dispatch. Call from
     * the dispatch transaction (or after it); issues once it commits. A
     * failure is logged and left for `billing:issue-missing-invoices`,
     * as for the other triggers — the dispatch itself stands.
     */
    public function whenDispatched(int $shipmentId): void
    {
        DB::afterCommit(function () use ($shipmentId) {
            try {
                $shipment = Shipment::query()->find($shipmentId, ['id', 'order_id']);
                $order = $shipment === null ? null : Order::query()->find($shipment->order_id, ['id', 'company_id', 'payment_method', 'status']);
                if ($shipment === null || $order === null || $order->company_id === null || $order->payment_method !== PaymentMethod::OnAccount->value) {
                    return;
                }

                if ($this->invoicingMode($order->company_id) === self::MODE_ON_COMPLETION) {
                    if ($order->status === 'dispatched') {
                        $this->issueForOrder($order->id);
                    }

                    return;
                }

                $this->issueForShipment($shipmentId);
            } catch (Throwable $e) {
                Log::error('Invoice not issued; run billing:issue-missing-invoices once the cause is fixed.', [
                    'shipment_id' => $shipmentId,
                    'trigger' => 'dispatched',
                    'exception' => $e,
                ]);
            }
        });
    }

    /** `invoicing.mode` for a company: its own setting, else global, else per shipment. */
    public function invoicingMode(?int $companyId): string
    {
        $rows = SystemConfiguration::query()
            ->where('config_key', self::MODE_KEY)
            ->where(fn ($q) => $q->where('scope', 'global')->when($companyId !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope', 'company')->where('company_id', $companyId))))
            ->get(['scope', 'value_text'])
            ->keyBy('scope');

        $mode = ($rows->get('company') ?? $rows->get('global'))->value_text ?? self::MODE_PER_SHIPMENT;

        return in_array($mode, [self::MODE_PER_SHIPMENT, self::MODE_ON_COMPLETION], true) ? $mode : self::MODE_PER_SHIPMENT;
    }

    /**
     * After commit: captured payments applied, the PDF queued, the
     * customer told, InvoiceIssued raised.
     */
    private function afterIssue(int $invoiceId, int $orderId): void
    {
        DB::afterCommit(function () use ($invoiceId, $orderId) {
            $this->paymentAllocationService->allocateInvoice($invoiceId);
            ArchiveInvoicePdf::dispatch($invoiceId);
            // 05.12 §12.2: sent at issue; the PDF is attached once archived.
            $this->notifications->invoiceIssued($invoiceId);
            event(new InvoiceIssued($invoiceId, $orderId));
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
     *
     * `$final` — this invoice completes the order's billing — consumes the
     * whole remaining hold. Otherwise (a per-shipment invoice with more to
     * come) the hold gives up the invoice's total and stays `held` for the
     * rest; `credit_holds_amount_chk` keeps a live hold above zero, so a
     * total that would exhaust it consumes it instead. Either way
     * `credit_held_minor` = Σ live hold amounts still holds (05.2 §7.2).
     */
    private function convertCreditHold(int $companyId, int $orderId, Invoice $invoice, bool $final): void
    {
        $hold = CreditHold::query()
            ->where('order_id', $orderId)
            ->where('status', 'held')
            ->lockForUpdate()
            ->first();

        $credit = Company::query()->whereKey($companyId)->firstOrFail(['id', 'credit_limit_minor', 'credit_used_minor', 'credit_held_minor']);
        $usageBefore = $credit->credit_used_minor + $credit->credit_held_minor;
        $released = 0;

        if ($hold !== null) {
            if ($final || $invoice->total_gross_minor >= $hold->amount_minor) {
                $released = $hold->amount_minor;
                $hold->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);
            } else {
                $released = $invoice->total_gross_minor;
                $hold->update(['amount_minor' => $hold->amount_minor - $released, 'invoice_id' => $invoice->id]);
            }
            Company::query()->whereKey($companyId)->decrement('credit_held_minor', $released);
        }

        Company::query()->whereKey($companyId)->increment('credit_used_minor', $invoice->total_gross_minor);

        // 05.12 §5.1.2: an invoice total can exceed its hold.
        $this->notifications->creditUsageChanged($companyId, $credit->credit_limit_minor, $usageBefore, $usageBefore - $released + $invoice->total_gross_minor, "invoice:{$invoice->id}");
    }
}
