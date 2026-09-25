<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Invoice;
use App\Models\Order;

/**
 * 05.12 §5.1, §12.2 `invoice.issued` — sent at issue with the number,
 * totals and a portal link. The archived PDF is attached when one exists
 * (once the PDF worker is bound).
 */
final class InvoiceIssued extends Notice
{
    use FormatsForMail;

    private const TERMS = [
        'prepay' => 'Payment in advance',
        'net7' => '7 days net',
        'net14' => '14 days net',
        'net30' => '30 days net',
        'net60' => '60 days net',
    ];

    public function __construct(public readonly int $invoiceId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::InvoiceIssued;
    }

    public function subject(): array
    {
        return ['invoice', $this->invoiceId];
    }

    public function attachmentId(): ?int
    {
        return Invoice::query()->find($this->invoiceId)?->archivedPdf()->value('id');
    }

    public function content(Recipient $recipient): MailContent
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);
        $order = Order::query()->findOrFail($invoice->order_id, ['id', 'public_id', 'order_number']);
        $receipt = $invoice->isReceipt();
        $document = $receipt ? 'Receipt' : 'VAT invoice';

        $facts = [
            ['label' => $receipt ? 'Receipt number' : 'Invoice number', 'value' => $invoice->invoice_number],
            ['label' => 'Date', 'value' => self::date($invoice->issued_at)],
            ['label' => 'Order', 'value' => $order->order_number],
        ];
        if (! $receipt) {
            $facts[] = ['label' => 'Terms', 'value' => self::TERMS[$invoice->payment_terms ?? ''] ?? (string) $invoice->payment_terms];
            $facts[] = ['label' => 'Due', 'value' => self::date($invoice->due_at)];
        }
        $facts[] = ['label' => 'Net', 'value' => self::money($invoice->subtotal_net_minor + $invoice->shipping_net_minor)];
        $facts[] = ['label' => 'VAT', 'value' => self::money($invoice->tax_minor)];
        $facts[] = ['label' => 'Total', 'value' => self::money($invoice->total_gross_minor)];
        if ($invoice->paid_minor > 0) {
            $facts[] = ['label' => 'Paid', 'value' => self::money($invoice->paid_minor)];
            $facts[] = ['label' => 'Balance due', 'value' => self::money($invoice->total_gross_minor - $invoice->paid_minor)];
        }

        $hasPdf = $invoice->archivedPdf()->exists();

        return new MailContent(
            subject: "{$document} {$invoice->invoice_number}",
            heading: $receipt ? 'Your receipt' : "{$document} {$invoice->invoice_number}",
            paragraphs: [$receipt
                ? "Thank you for your payment for order {$order->order_number}."
                : "Please find the details of invoice {$invoice->invoice_number} for order {$order->order_number} below."],
            facts: $facts,
            actionLabel: 'View your order',
            actionUrl: route('orders.confirmation', ['order' => $order->public_id]),
            closing: [$hasPdf ? "A PDF copy of this {$this->lower($document)} is attached." : 'Keep this email for your records.'],
        );
    }

    private function lower(string $document): string
    {
        return $document === 'Receipt' ? 'receipt' : 'invoice';
    }
}
