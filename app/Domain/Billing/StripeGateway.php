<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\PaymentGatewayException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Stripe card payments (07 §6.4). Card details are entered into Stripe
 * Elements in the browser and go straight to Stripe: nothing here ever
 * sees a card number, expiry or CVC, which keeps the platform in PCI DSS
 * SAQ-A scope. What comes back is the PaymentIntent's id, status, amount,
 * and — from the charge — the card's brand and last four digits.
 *
 * Intents are created with `capture_method = manual`: confirming one in
 * the browser *authorises* the amount, and capture waits until the order
 * has committed (04 §4.4). A decline, or 3-D Secure failing, leaves the
 * intent in `requires_payment_method` with the reason on
 * `last_payment_error`; CardIntent carries its `decline_code`.
 */
final class StripeGateway implements PaymentGateway
{
    private readonly StripeClient $client;

    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client ?? new StripeClient((string) config('services.stripe.secret'));
    }

    public function createAuthorisation(int $amountMinor, string $currency, array $metadata, string $idempotencyKey): CardIntent
    {
        return $this->call(fn () => $this->client->paymentIntents->create([
            'amount' => $amountMinor,
            'currency' => strtolower($currency),
            'capture_method' => 'manual',
            'payment_method_types' => ['card'],
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey]));
    }

    public function retrieve(string $intentId): CardIntent
    {
        return $this->call(fn () => $this->client->paymentIntents->retrieve($intentId, ['expand' => ['latest_charge']]));
    }

    public function capture(string $intentId): CardIntent
    {
        return $this->call(fn () => $this->client->paymentIntents->capture($intentId, ['expand' => ['latest_charge']]));
    }

    public function cancel(string $intentId): void
    {
        $this->call(fn () => $this->client->paymentIntents->cancel($intentId));
    }

    /**
     * @param  callable(): PaymentIntent  $request
     */
    private function call(callable $request): CardIntent
    {
        try {
            return self::toCardIntent($request());
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), 0, $e);
        }
    }

    public static function toCardIntent(PaymentIntent $intent): CardIntent
    {
        /** @var array<string, mixed> $data */
        $data = $intent->toArray();

        $charge = is_array($data['latest_charge'] ?? null) ? $data['latest_charge'] : [];
        $details = is_array($charge['payment_method_details'] ?? null) ? $charge['payment_method_details'] : [];
        $card = is_array($details['card'] ?? null) ? $details['card'] : [];
        $error = is_array($data['last_payment_error'] ?? null) ? $data['last_payment_error'] : [];
        $metadata = is_array($data['metadata'] ?? null) ? array_map('strval', $data['metadata']) : [];

        $declineCode = $error['decline_code'] ?? $error['code'] ?? null;

        return new CardIntent(
            id: (string) ($data['id'] ?? ''),
            clientSecret: is_string($data['client_secret'] ?? null) ? $data['client_secret'] : null,
            status: (string) ($data['status'] ?? ''),
            amountMinor: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            metadata: $metadata,
            cardBrand: is_string($card['brand'] ?? null) ? $card['brand'] : null,
            cardLast4: is_string($card['last4'] ?? null) ? $card['last4'] : null,
            declineCode: is_string($declineCode) ? $declineCode : null,
        );
    }
}
