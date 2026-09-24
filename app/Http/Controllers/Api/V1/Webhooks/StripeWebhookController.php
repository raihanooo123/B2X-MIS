<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Billing\CardPayments;
use App\Domain\Billing\StripeGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * POST /api/v1/webhooks/stripe — Stripe telling us what happened to a
 * card payment, which may arrive before, after or instead of the checkout
 * request's own view (a buyer closing the tab mid-capture, say).
 *
 * Only a correctly signed event is accepted (`Stripe-Signature`, verified
 * against STRIPE_WEBHOOK_SECRET; 06 §11 "signed webhooks"). Handling is
 * idempotent on `payments_gateway_reference_uq`: every event moves the
 * one payments row for its PaymentIntent from the state it expects, or
 * does nothing, so a redelivered event (Stripe retries until it gets a
 * 2xx) never records anything twice.
 *
 *   - payment_intent.succeeded → the row becomes `captured`; the order
 *     becomes `paid` (CardPayments::markCaptured).
 *   - payment_intent.canceled  → an `authorized` row becomes `voided`.
 *   - anything else, including payment_failed (a decline records nothing,
 *     07 §6.4) → acknowledged and ignored.
 *
 * An intent with no payments row — authorised but never turned into an
 * order — is acknowledged and logged: the checkout path already released
 * it or its authorisation lapses.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            Log::critical('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set.');

            return response()->json(['error' => 'not configured'], 503);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature', ''), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $object = $event->data->object ?? null;
        if (! $object instanceof PaymentIntent) {
            return response()->json(['received' => true]);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->captured($object),
            'payment_intent.canceled' => CardPayments::markVoided($object->id),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function captured(PaymentIntent $intent): void
    {
        if (CardPayments::markCaptured($intent->id, StripeGateway::toCardIntent($intent)) === null) {
            Log::warning('Stripe reported a captured payment with no order.', ['payment_intent' => $intent->id]);
        }
    }
}
