/**
 * What to tell a buyer whose card was not authorised — the browser's copy
 * of app/Domain/Billing/DeclineMessages.php, for declines Stripe.js
 * reports before the server is involved. Keep the two in step.
 */
const MESSAGES: Record<string, string> = {
    insufficient_funds: 'Your card was declined for insufficient funds. Try another card, or pay by bank transfer.',
    expired_card: 'Your card has expired. Check the expiry date or use another card.',
    incorrect_cvc: 'The security code (CVC) is incorrect. Check it and try again.',
    invalid_cvc: 'The security code (CVC) is incorrect. Check it and try again.',
    incorrect_number: 'The card number is incorrect. Check it and try again.',
    invalid_number: 'The card number is incorrect. Check it and try again.',
    invalid_expiry_month: 'The expiry date is not valid. Check it and try again.',
    invalid_expiry_year: 'The expiry date is not valid. Check it and try again.',
    incorrect_zip: 'The postcode does not match your card. Check it and try again.',
    lost_card: 'Your card was declined. Please use another card.',
    stolen_card: 'Your card was declined. Please use another card.',
    card_not_supported: 'This card does not support this type of purchase. Please use another card.',
    authentication_required: 'Your bank needs you to confirm this payment. Try again and complete the security check.',
    payment_intent_authentication_failure: 'The security check with your bank was not completed. Try again, or use another card.',
    processing_error: 'Something went wrong processing your card. Please try again in a moment.',
};

export const GENERAL_DECLINE = 'Your card was declined. Please check the details, or try another card or payment method.';

export function declineMessage(error: { code?: string; decline_code?: string; message?: string; type?: string }): string {
    const known = (error.decline_code && MESSAGES[error.decline_code]) || (error.code && MESSAGES[error.code]);
    if (known) {
        return known;
    }

    // Stripe's own card-error messages are written for buyers; anything else is not.
    return error.type === 'card_error' || error.type === 'validation_error' ? (error.message ?? GENERAL_DECLINE) : GENERAL_DECLINE;
}
