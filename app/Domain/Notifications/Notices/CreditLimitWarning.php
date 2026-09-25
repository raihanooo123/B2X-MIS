<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Company;

/**
 * 05.12 §5.1.2 `credit.limit_warning` — usage crossed 80% of the limit, to
 * the company's owners. One per breach: the occurrence is the subject
 * whose write caused the crossing.
 */
final class CreditLimitWarning extends Notice
{
    use FormatsForMail;

    public function __construct(
        public readonly int $companyId,
        public readonly string $cause,
    ) {}

    public function key(): NotificationKey
    {
        return NotificationKey::CreditLimitWarning;
    }

    public function subject(): array
    {
        return ['company', $this->companyId];
    }

    public function occurrence(): string
    {
        return $this->cause;
    }

    public function content(Recipient $recipient): MailContent
    {
        $company = Company::query()->findOrFail($this->companyId);
        $usage = $company->credit_used_minor + $company->credit_held_minor;

        return new MailContent(
            subject: 'Your account has used 80% of its credit limit',
            heading: 'Your credit is running low',
            paragraphs: [
                "{$company->name} has now used at least 80% of its credit limit, including orders not yet invoiced.",
                'On-account orders that would exceed the limit will not be accepted. Paying outstanding invoices frees up credit, or contact us to discuss your limit.',
            ],
            facts: [
                ['label' => 'Credit limit', 'value' => self::money($company->credit_limit_minor)],
                ['label' => 'Used, including open orders', 'value' => self::money($usage)],
                ['label' => 'Available', 'value' => self::money($company->credit_limit_minor - $usage)],
            ],
        );
    }
}
