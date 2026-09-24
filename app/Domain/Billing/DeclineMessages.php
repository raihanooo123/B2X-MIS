<?php

namespace App\Domain\Billing;

/**
 * What to tell a buyer whose card was not authorised — in their terms,
 * with what to do next, and never more than the gateway told us. Stripe's
 * `decline_code` (or error `code`) selects the message; anything unknown
 * gets the general one. The browser has the same table for declines it
 * sees first (resources/js/lib/payments/declines.ts).
 */
final class DeclineMessages
{
    private const MESSAGES = [
        'insufficient_funds' => 'Your card was declined for insufficient funds. Try another card, or pay by bank transfer.',
        'expired_card' => 'Your card has expired. Check the expiry date or use another card.',
        'incorrect_cvc' => 'The security code (CVC) is incorrect. Check it and try again.',
        'incorrect_number' => 'The card number is incorrect. Check it and try again.',
        'incorrect_zip' => 'The postcode does not match your card. Check it and try again.',
        'lost_card' => 'Your card was declined. Please use another card.',
        'stolen_card' => 'Your card was declined. Please use another card.',
        'card_not_supported' => 'This card does not support this type of purchase. Please use another card.',
        'authentication_required' => 'Your bank needs you to confirm this payment. Try again and complete the security check.',
        'payment_intent_authentication_failure' => 'The security check with your bank was not completed. Try again, or use another card.',
        'processing_error' => 'Something went wrong processing your card. Please try again in a moment.',
    ];

    public const GENERAL = 'Your card was declined. Please check the details, or try another card or payment method.';

    public static function for(?string $code): string
    {
        return $code === null ? self::GENERAL : (self::MESSAGES[$code] ?? self::GENERAL);
    }
}
