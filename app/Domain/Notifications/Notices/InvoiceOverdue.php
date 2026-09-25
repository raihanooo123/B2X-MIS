<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Company;
use App\Models\Invoice;

/**
 * 05.12 §5.1 `invoice.overdue` — 05.2 §9, §11: past `due_at` plus the
 * grace period. To the invoice recipients, the accounts role and the
 * assigned rep; once per invoice.
 */
final class InvoiceOverdue extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $invoiceId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::InvoiceOverdue;
    }

    public function subject(): array
    {
        return ['invoice', $this->invoiceId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);
        $company = $invoice->company_id === null ? null : Company::query()->find($invoice->company_id, ['id', 'name', 'account_code']);

        $facts = [];
        if ($company !== null) {
            $facts[] = ['label' => 'Account', 'value' => "{$company->name} ({$company->account_code})"];
        }
        $facts[] = ['label' => 'Invoice', 'value' => $invoice->invoice_number];
        $facts[] = ['label' => 'Was due', 'value' => self::date($invoice->due_at)];
        $facts[] = ['label' => 'Balance due', 'value' => self::money($invoice->total_gross_minor - $invoice->paid_minor)];

        return new MailContent(
            subject: "Invoice {$invoice->invoice_number} is overdue",
            heading: 'An invoice is overdue',
            paragraphs: ["Invoice {$invoice->invoice_number} was due on ".self::date($invoice->due_at).' and is still unpaid. If payment is already on its way, thank you — please ignore this reminder.'],
            facts: $facts,
        );
    }
}
