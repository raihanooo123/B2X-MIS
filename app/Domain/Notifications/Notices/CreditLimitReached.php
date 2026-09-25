<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Company;

/**
 * 05.12 §5.1 `credit.limit_reached` — 05.2 §11: an on-account checkout
 * refused for insufficient credit, to the accounts role. The buyer sees it
 * on the checkout page. At most once per company per day, so a buyer
 * retrying does not flood accounts.
 */
final class CreditLimitReached extends Notice
{
    use FormatsForMail;

    public function __construct(
        public readonly int $companyId,
        public readonly int $attemptedMinor,
        public readonly string $day,
    ) {}

    public function key(): NotificationKey
    {
        return NotificationKey::CreditLimitReached;
    }

    public function subject(): array
    {
        return ['company', $this->companyId];
    }

    public function occurrence(): string
    {
        return $this->day;
    }

    public function content(Recipient $recipient): MailContent
    {
        $company = Company::query()->findOrFail($this->companyId);
        $available = $company->credit_limit_minor - $company->credit_used_minor - $company->credit_held_minor;

        return new MailContent(
            subject: "Credit limit reached: {$company->name}",
            heading: 'An on-account order was refused',
            paragraphs: ["{$company->name} tried to place an on-account order that exceeds its available credit. The order was not placed."],
            facts: [
                ['label' => 'Account', 'value' => "{$company->name} ({$company->account_code})"],
                ['label' => 'Order total', 'value' => self::money($this->attemptedMinor)],
                ['label' => 'Credit limit', 'value' => self::money($company->credit_limit_minor)],
                ['label' => 'Used', 'value' => self::money($company->credit_used_minor)],
                ['label' => 'Held', 'value' => self::money($company->credit_held_minor)],
                ['label' => 'Available', 'value' => self::money($available)],
            ],
        );
    }
}
