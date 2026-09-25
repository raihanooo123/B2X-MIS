<?php

namespace App\Domain\Billing\Documents;

use App\Domain\Billing\Exceptions\InvoiceTotalsMismatchException;
use App\Domain\Billing\SellerDetails;
use App\Filament\Support\MoneyFormatter;
use App\Models\Invoice;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use LogicException;

/**
 * Builds the printable document for an issued invoice or receipt.
 *
 * A VAT invoice carries what HMRC requires of a full VAT invoice: a
 * unique sequential number, the invoice date and time of supply, the
 * supplier's name, address and VAT registration number, the customer's
 * name and address, and per line a description, quantity, unit price
 * excluding VAT, VAT rate and line net — then the net and VAT totals per
 * rate, and the total payable. Payment terms and due date are
 * snapshotted on the invoice (02 §14.5.2).
 *
 * A receipt (02 §21.2) is the same document titled "Receipt", with no
 * payment terms or due date.
 *
 * Lines are derived from `order_lines`, never re-priced (invariant 4).
 * Carriage is its own line at its own VAT rate (02 §20.2). Every figure
 * is summed from integers already on the order: nothing is recomputed,
 * so the document agrees with the ledger exactly. It is checked before
 * rendering, and a document that does not add up is refused.
 *
 * Quantities print in packs with their base-unit equivalent (invariant 2);
 * the unit price is per base unit, at e4 precision (03 §3.3).
 */
final class InvoiceDocumentBuilder
{
    private const TERMS_LABELS = [
        'prepay' => 'Payment in advance',
        'net7' => '7 days net',
        'net14' => '14 days net',
        'net30' => '30 days net',
        'net60' => '60 days net',
    ];

    public function build(Invoice $invoice): InvoiceDocument
    {
        if ($invoice->shipment_id !== null) {
            // Per-shipment line derivation through shipment_lines (02 §14.6)
            // lands with the dispatch flow; nothing issues these yet.
            throw new LogicException("Invoice {$invoice->id} is per-shipment; per-shipment documents are not built yet.");
        }

        $invoice->loadMissing(['order.addresses', 'order.user', 'company', 'orderLines']);
        $order = $invoice->order ?? throw new LogicException("Invoice {$invoice->id} has no order.");
        $receipt = $invoice->isReceipt();

        /** @var array<int, array{net: int, vat: int}> $byRate keyed by rate in basis points */
        $byRate = [];
        $lines = [];
        foreach ($invoice->orderLines as $line) {
            /** @var OrderLine $line */
            $lines[] = [
                'line_no' => $line->line_no,
                'sku_code' => $line->sku_code_snapshot,
                'description' => $line->name_snapshot,
                'pack_label' => $line->pack_label_snapshot,
                'pack_qty' => $line->pack_qty,
                'pack_base_units' => $line->pack_base_units,
                'base_qty' => $line->base_qty,
                'unit_price_net_e4' => $line->unit_price_net_e4,
                'unit_price_net' => MoneyFormatter::e4($line->unit_price_net_e4),
                ...$this->money('line_net', $line->line_net_minor),
                'vat_rate_bp' => $line->tax_rate_bp,
                'vat_rate' => self::percent($line->tax_rate_bp),
                ...$this->money('line_vat', $line->line_tax_minor),
            ];
            $byRate[$line->tax_rate_bp]['net'] = ($byRate[$line->tax_rate_bp]['net'] ?? 0) + $line->line_net_minor;
            $byRate[$line->tax_rate_bp]['vat'] = ($byRate[$line->tax_rate_bp]['vat'] ?? 0) + $line->line_tax_minor;
        }

        $carriage = null;
        if ($invoice->shipping_net_minor > 0 || $order->shipping_tax_minor > 0) {
            $rateBp = $order->shipping_tax_rate_bp ?? 0;
            $carriage = [
                'description' => 'Carriage',
                ...$this->money('net', $invoice->shipping_net_minor),
                'vat_rate_bp' => $rateBp,
                'vat_rate' => self::percent($rateBp),
                ...$this->money('vat', $order->shipping_tax_minor),
            ];
            $byRate[$rateBp]['net'] = ($byRate[$rateBp]['net'] ?? 0) + $invoice->shipping_net_minor;
            $byRate[$rateBp]['vat'] = ($byRate[$rateBp]['vat'] ?? 0) + $order->shipping_tax_minor;
        }

        krsort($byRate);
        $vatSummary = [];
        foreach ($byRate as $rateBp => $sums) {
            $vatSummary[] = [
                'rate_bp' => $rateBp,
                'rate' => self::percent($rateBp),
                ...$this->money('net', $sums['net']),
                ...$this->money('vat', $sums['vat']),
            ];
        }

        $this->assertAddsUp($invoice, $byRate);

        $seller = SellerDetails::fromConfiguration();
        if (! $receipt && $seller->vatNumber === null) {
            throw new LogicException("Invoice {$invoice->id}: cannot print a VAT invoice without seller.vat_number (02 §21.3).");
        }

        return new InvoiceDocument([
            'kind' => $receipt ? 'receipt' : 'vat_invoice',
            'title' => $receipt ? 'Receipt' : 'VAT invoice',
            'number' => $invoice->invoice_number,
            'issued_on' => $invoice->issued_at->toDateString(),
            // Every document is issued at the moment of supply it records
            // (placement or payment for prepaid orders, dispatch on account).
            'tax_point' => $invoice->issued_at->toDateString(),
            'payment_terms' => $receipt ? null : $invoice->payment_terms,
            'payment_terms_label' => $receipt || $invoice->payment_terms === null ? null : self::TERMS_LABELS[$invoice->payment_terms],
            'due_on' => $receipt ? null : $invoice->due_at?->toDateString(),
            'order_number' => $order->order_number,
            'customer_reference' => $order->customer_reference,
            'currency' => $invoice->currency,
            'seller' => [
                'legal_name' => $seller->legalName,
                'address_lines' => $seller->addressLines,
                'vat_number' => $seller->vatNumber,
                'company_number' => $seller->companyNumber,
            ],
            'customer' => $this->customer($invoice),
            'lines' => $lines,
            'carriage' => $carriage,
            'vat_summary' => $vatSummary,
            'totals' => [
                ...$this->money('subtotal_net', $invoice->subtotal_net_minor),
                ...$this->money('discount_net', $invoice->discount_net_minor),
                ...$this->money('shipping_net', $invoice->shipping_net_minor),
                ...$this->money('vat', $invoice->tax_minor),
                ...$this->money('total_gross', $invoice->total_gross_minor),
                ...$this->money('paid', $invoice->paid_minor),
                ...$this->money('balance_due', $invoice->total_gross_minor - $invoice->paid_minor),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Invoice $invoice): array
    {
        $order = $invoice->order;
        $addresses = $order?->addresses->keyBy('address_type');
        /** @var OrderAddress|null $address */
        $address = $addresses?->get('billing') ?? $addresses?->get('delivery');

        $addressLines = $address === null ? [] : array_values(array_filter([
            $address->company_name,
            $address->line1,
            $address->line2,
            $address->city,
            $address->county,
            $address->postcode,
            $address->country_code === 'GB' ? null : $address->country_code,
        ], fn (?string $part) => $part !== null && trim($part) !== ''));

        if ($invoice->company !== null) {
            return [
                'name' => $invoice->company->name,
                'account_code' => $invoice->company->account_code,
                'vat_number' => $invoice->company->vat_number,
                'contact_name' => $address?->contact_name,
                'address_lines' => $addressLines,
            ];
        }

        $user = $order?->user;
        $name = $user === null ? null : trim($user->first_name.' '.$user->last_name);

        return [
            'name' => $name !== null && $name !== '' ? $name : $address?->contact_name,
            'account_code' => null,
            'vat_number' => null,
            'contact_name' => $address?->contact_name,
            'address_lines' => $addressLines,
        ];
    }

    /**
     * Σ net by rate = subtotal + carriage; Σ VAT by rate = the invoice's
     * VAT; net + VAT = total (03 §7A.7's property). Any disagreement is a
     * bug upstream, and the document is not printed.
     *
     * @param  array<int, array{net: int, vat: int}>  $byRate
     */
    private function assertAddsUp(Invoice $invoice, array $byRate): void
    {
        $net = array_sum(array_column($byRate, 'net'));
        $vat = array_sum(array_column($byRate, 'vat'));

        if ($net !== $invoice->subtotal_net_minor + $invoice->shipping_net_minor) {
            throw new InvoiceTotalsMismatchException($invoice->id, "lines net {$net}, invoice net ".($invoice->subtotal_net_minor + $invoice->shipping_net_minor));
        }
        if ($vat !== $invoice->tax_minor) {
            throw new InvoiceTotalsMismatchException($invoice->id, "lines VAT {$vat}, invoice VAT {$invoice->tax_minor}");
        }
        if ($net + $vat !== $invoice->total_gross_minor) {
            throw new InvoiceTotalsMismatchException($invoice->id, 'net + VAT '.($net + $vat).", invoice total {$invoice->total_gross_minor}");
        }
    }

    /**
     * An amount as its integer and, alongside, its formatted string (06 §3).
     *
     * @return array<string, int|string|null>
     */
    private function money(string $name, int $amountMinor): array
    {
        return [
            "{$name}_minor" => $amountMinor,
            $name => MoneyFormatter::minor($amountMinor),
        ];
    }

    /** Basis points to a percentage string, integer arithmetic only: 2000 → "20%", 1750 → "17.5%". */
    public static function percent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = rtrim(str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0');

        return $whole.($fraction === '' ? '' : '.'.$fraction).'%';
    }
}
