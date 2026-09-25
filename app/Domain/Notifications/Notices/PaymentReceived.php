<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Invoice;

/**
 * 05.12 §5.1.1 `payment.received` — a payment applied to an invoice on an
 * order that is not a card order (a card order's receipt or invoice is its
 * acknowledgement). One message per payment per invoice.
 */
final class PaymentReceived extends Notice
{
    use FormatsForMail;

    public function __construct(
        public readonly int $invoiceId,
        public readonly int $paymentId,
        public readonly int $amountMinor,
    ) {}

    public function key(): NotificationKey
    {
        return NotificationKey::PaymentReceived;
    }

    public function subject(): array
    {
        return ['invoice', $this->invoiceId];
    }

    public function occurrence(): string
    {
        return "payment:{$this->paymentId}";
    }

    public function content(Recipient $recipient): MailContent
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);
        $balance = $invoice->total_gross_minor - $invoice->paid_minor;

        return new MailContent(
            subject: "Payment received for invoice {$invoice->invoice_number}",
            heading: 'Thank you for your payment',
            paragraphs: ["We have applied your payment to invoice {$invoice->invoice_number}."],
            facts: [
                ['label' => 'Invoice', 'value' => $invoice->invoice_number],
                ['label' => 'Payment applied', 'value' => self::money($this->amountMinor)],
                ['label' => 'Invoice total', 'value' => self::money($invoice->total_gross_minor)],
                ['label' => 'Balance remaining', 'value' => self::money($balance)],
            ],
            closing: [$balance <= 0 ? 'This invoice is now paid in full.' : 'The remaining balance is due by the invoice due date.'],
        );
    }
}
