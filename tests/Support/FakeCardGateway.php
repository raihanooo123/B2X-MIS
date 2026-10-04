<?php

namespace Tests\Support;

use App\Domain\Billing\CardIntent;
use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentGateway;
use Illuminate\Support\Str;

/**
 * Stripe replaced for tests (07 §6.4): no network, and the test says what
 * the card did — authorised, declined, captured, released or refunded.
 */
final class FakeCardGateway implements PaymentGateway
{
    /** @var array<string, CardIntent> */
    public array $intents = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<string> */
    public array $captured = [];

    public int $created = 0;

    public bool $failCapture = false;

    public bool $failRefund = false;

    /** @var list<array{intent: string, amount_minor: int, key: string}> */
    public array $refunds = [];

    public function createAuthorisation(int $amountMinor, string $currency, array $metadata, string $idempotencyKey): CardIntent
    {
        $this->created++;
        $id = 'pi_'.Str::random(20);

        return $this->intents[$id] = new CardIntent($id, $id.'_secret_'.Str::random(8), 'requires_payment_method', $amountMinor, $currency, $metadata);
    }

    public function retrieve(string $intentId): CardIntent
    {
        return $this->intents[$intentId] ?? throw new PaymentGatewayException("No such intent {$intentId}");
    }

    public function capture(string $intentId): CardIntent
    {
        if ($this->failCapture) {
            throw new PaymentGatewayException('Capture window expired');
        }
        $this->captured[] = $intentId;

        return $this->set($intentId, 'succeeded');
    }

    public function cancel(string $intentId): void
    {
        $this->cancelled[] = $intentId;
        $this->set($intentId, 'canceled');
    }

    public function refund(string $intentId, int $amountMinor, string $idempotencyKey): string
    {
        if ($this->failRefund) {
            throw new PaymentGatewayException('Card expired');
        }
        $this->refunds[] = ['intent' => $intentId, 'amount_minor' => $amountMinor, 'key' => $idempotencyKey];

        return 're_'.Str::random(20);
    }

    /** What Stripe Elements does when the card is accepted. */
    public function authorise(string $intentId, string $brand = 'visa', string $last4 = '4242'): void
    {
        $this->set($intentId, 'requires_capture', $brand, $last4);
    }

    /** What Stripe Elements leaves behind when the card is declined. */
    public function decline(string $intentId, string $code): void
    {
        $this->set($intentId, 'requires_payment_method', declineCode: $code);
    }

    private function set(string $id, string $status, ?string $brand = null, ?string $last4 = null, ?string $declineCode = null): CardIntent
    {
        $i = $this->retrieve($id);

        return $this->intents[$id] = new CardIntent($i->id, $i->clientSecret, $status, $i->amountMinor, $i->currency, $i->metadata, $brand ?? $i->cardBrand, $last4 ?? $i->cardLast4, $declineCode);
    }
}
