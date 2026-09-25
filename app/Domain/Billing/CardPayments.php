<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Events\PaymentCaptured;
use App\Domain\Ordering\OrderPayments;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The `payments` rows for card payments (02 §14.5.1, §19). Only the
 * gateway's reference, amount and the card's brand and last four digits
 * are ever stored (07 §6.4).
 *
 * A row is written once a card is *authorised* — inside the order's own
 * transaction, so an order and its payment exist together or not at all —
 * and moves to `captured` after the order commits, or `voided` if the
 * authorisation is released. A declined card writes nothing.
 *
 * Capture marks the order `paid` in the same transaction as the row
 * (OrderPayments::markPaid), never through a listener: a payment captured
 * at the gateway must never leave its order recorded as unpaid.
 *
 * Every transition is keyed on `payments_gateway_reference_uq` (gateway,
 * gateway_reference) and is idempotent: the checkout request and Stripe's
 * webhook both report a capture, in either order, any number of times,
 * and the row moves once.
 */
final class CardPayments
{
    public const GATEWAY = 'stripe';

    /**
     * Call inside the order transaction. A second order against the same
     * intent fails on the unique index and rolls that order back.
     */
    public static function recordAuthorisation(int $orderId, ?int $companyId, CardIntent $intent): Payment
    {
        return Payment::query()->create([
            'order_id' => $orderId,
            'company_id' => $companyId,
            'type' => 'payment',
            'gateway' => self::GATEWAY,
            'gateway_reference' => $intent->id,
            'status' => 'authorized',
            'amount_minor' => $intent->amountMinor,
            'currency' => $intent->currency,
            'card_brand' => $intent->cardBrand,
            'card_last4' => $intent->cardLast4,
            'authorized_at' => now(),
        ]);
    }

    public static function findByIntent(string $intentId): ?Payment
    {
        return Payment::query()->where('gateway', self::GATEWAY)->where('gateway_reference', $intentId)->first();
    }

    /**
     * Authorised → captured; anything else is left alone. Returns the row,
     * or null when no order was ever placed against this intent.
     */
    public static function markCaptured(string $intentId, ?CardIntent $captured = null): ?Payment
    {
        return DB::transaction(function () use ($intentId, $captured) {
            $payment = Payment::query()->where('gateway', self::GATEWAY)->where('gateway_reference', $intentId)->lockForUpdate()->first();
            if ($payment === null || $payment->status !== 'authorized') {
                return $payment;
            }

            $payment->update([
                'status' => 'captured',
                'captured_at' => now(),
                'card_brand' => $payment->card_brand ?? $captured?->cardBrand,
                'card_last4' => $payment->card_last4 ?? $captured?->cardLast4,
            ]);

            $paymentId = (int) $payment->id;
            $orderId = $payment->order_id === null ? null : (int) $payment->order_id;

            // Same transaction: captured and paid commit together.
            if ($orderId !== null) {
                OrderPayments::markPaid($orderId);
            }

            DB::afterCommit(function () use ($paymentId, $orderId) {
                event(new PaymentCaptured($paymentId, $orderId));
                (new PaymentAllocationService)->allocatePayment($paymentId);
                // 05.5 §7.3: a card order is invoiced (receipted) at capture.
                if ($orderId !== null) {
                    (new InvoiceService)->whenPaid($orderId);
                }
            });

            return $payment;
        });
    }

    /** Authorised → voided (the authorisation was released). */
    public static function markVoided(string $intentId): void
    {
        Payment::query()
            ->where('gateway', self::GATEWAY)
            ->where('gateway_reference', $intentId)
            ->where('status', 'authorized')
            ->update(['status' => 'voided', 'updated_at' => now()]);
    }

    /** Capture failed after the order committed: the order stays unpaid for a person to follow up. */
    public static function markCaptureFailed(string $intentId, string $reason): void
    {
        Payment::query()
            ->where('gateway', self::GATEWAY)
            ->where('gateway_reference', $intentId)
            ->where('status', 'authorized')
            ->update(['failure_reason' => mb_substr($reason, 0, 500), 'updated_at' => now()]);
    }
}
