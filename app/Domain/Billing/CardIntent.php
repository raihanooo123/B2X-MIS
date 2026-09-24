<?php

namespace App\Domain\Billing;

/**
 * One card PaymentIntent as this platform needs to see it — never card
 * data (07 §6.4, SAQ-A): the gateway's reference, the amount, where it
 * stands, and, once a card has been presented, its brand and last four
 * digits.
 *
 * `status` is Stripe's own: `requires_payment_method` (new, or the last
 * card was declined), `requires_confirmation`, `requires_action` (3-D
 * Secure), `requires_capture` (authorised — the only state an order may
 * be placed against), `succeeded` (captured), `canceled`.
 */
final readonly class CardIntent
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public ?string $clientSecret,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public array $metadata = [],
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?string $declineCode = null,
    ) {}

    public function isAuthorised(): bool
    {
        return $this->status === 'requires_capture';
    }

    public function isCaptured(): bool
    {
        return $this->status === 'succeeded';
    }

    /** Still usable for another attempt: not yet authorised, captured or cancelled. */
    public function isReusable(): bool
    {
        return in_array($this->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true);
    }
}
