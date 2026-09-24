<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\PaymentGatewayException;

/**
 * The card gateway, as checkout uses it (04 §4.4: "payment authorisation
 * happens before, and is captured after" — never inside the order
 * transaction). StripeGateway is the implementation; tests bind a fake.
 */
interface PaymentGateway
{
    /**
     * A manual-capture intent for `amountMinor`: the browser presents the
     * card to it (Stripe Elements), which only authorises the amount.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws PaymentGatewayException
     */
    public function createAuthorisation(int $amountMinor, string $currency, array $metadata, string $idempotencyKey): CardIntent;

    /** @throws PaymentGatewayException */
    public function retrieve(string $intentId): CardIntent;

    /** Takes the authorised money, after the order has committed. @throws PaymentGatewayException */
    public function capture(string $intentId): CardIntent;

    /** Releases an authorisation the order will not use. @throws PaymentGatewayException */
    public function cancel(string $intentId): void;
}
