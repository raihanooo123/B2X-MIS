<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Invoice;

/** 05.12 §5.1 `invoice.due_soon` — 05.2 §11: 3 days before `due_at`. Once per invoice. */
final class InvoiceDueSoon extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $invoiceId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::InvoiceDueSoon;
    }

    public function subject(): array
    {
        return ['invoice', $this->invoiceId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);

        return new MailContent(
            subject: "Invoice {$invoice->invoice_number} is due on ".self::date($invoice->due_at),
            heading: 'An invoice is due soon',
            paragraphs: ["Invoice {$invoice->invoice_number} is due on ".self::date($invoice->due_at).'. If you have already paid, thank you — please ignore this reminder.'],
            facts: [
                ['label' => 'Invoice', 'value' => $invoice->invoice_number],
                ['label' => 'Due', 'value' => self::date($invoice->due_at)],
                ['label' => 'Balance due', 'value' => self::money($invoice->total_gross_minor - $invoice->paid_minor)],
            ],
        );
    }
}
