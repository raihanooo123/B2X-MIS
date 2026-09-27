<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Billing\PaymentTerms;
use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\PriceTier;

/**
 * 05.12 §5.2 `application.approved` — account code, tier, terms and
 * credit limit, to the applicant (05.2 §5.6, §11).
 */
final class ApplicationApproved extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $applicationId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationApproved;
    }

    public function subject(): array
    {
        return ['b2b_application', $this->applicationId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $application = B2bApplication::query()->findOrFail($this->applicationId, ['id', 'company_id', 'contact_name']);
        $company = Company::query()->findOrFail($application->company_id, ['id', 'name', 'account_code', 'price_tier_id', 'payment_terms', 'credit_limit_minor']);
        $tier = PriceTier::query()->whereKey($company->price_tier_id)->value('name');
        $terms = PaymentTerms::tryFrom($company->payment_terms);

        $facts = [
            ['label' => 'Account code', 'value' => $company->account_code],
            ['label' => 'Price tier', 'value' => is_string($tier) ? $tier : '—'],
            ['label' => 'Payment terms', 'value' => $terms?->label() ?? $company->payment_terms],
        ];
        if ($company->credit_limit_minor > 0) {
            $facts[] = ['label' => 'Credit limit', 'value' => self::money($company->credit_limit_minor)];
        }

        return new MailContent(
            subject: "Your trade account is approved — {$company->account_code}",
            heading: "Welcome, {$application->contact_name}",
            paragraphs: [
                "Your trade account for {$company->name} is approved. Sign in to see your trade prices — they apply from your next page load.",
            ],
            facts: $facts,
            actionLabel: 'Sign in',
            actionUrl: route('login'),
            closing: ['Please quote your account code when you contact us.'],
        );
    }
}
