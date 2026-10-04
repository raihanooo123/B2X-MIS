<?php

namespace App\Domain\Billing;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Refund rows on `payments` (02 §14.5.1): `type = 'refund'`, pointing at the
 * payment they refund (`refunded_payment_id`), never a mutated amount on
 * the original. 05.4 §13.2, §13.6: a consumer is refunded to the means they
 * paid with.
 *
 * A refund is recorded `pending` inside the transaction that decides it,
 * and the gateway is called only after that commits (CLAUDE.md invariant
 * 6). Success moves it to `captured` (money moved, the same word the
 * payment it reverses uses), with the gateway's refund reference; the
 * original payment becomes `refunded` or `part_refunded`, and so does the
 * order's `payment_status`. A gateway failure moves it to `failed` with
 * the reason, for accounts to repay by bank transfer.
 *
 * A BACS refund stays `pending` until accounts record the transfer.
 */
final class Refunds
{
    /**
     * Call inside the transaction that decides the refund. `$gateway`
     * overrides the original's — `bacs` when a refused card refund is
     * repaid by bank transfer.
     */
    public static function recordPending(Payment $original, int $amountMinor, ?string $gateway = null): Payment
    {
        return Payment::query()->create([
            'order_id' => $original->order_id,
            'company_id' => $original->company_id,
            'type' => 'refund',
            // A failed card refund is repaid by bank transfer (05.4 §13.6).
            'gateway' => $gateway ?? $original->gateway,
            'status' => 'pending',
            'amount_minor' => $amountMinor,
            'currency' => $original->currency,
            'refunded_payment_id' => $original->id,
        ]);
    }

    /** What has already been refunded, or is on its way, against a payment. */
    public static function refundedOrPendingMinor(int $paymentId): int
    {
        return (int) Payment::query()
            ->where('refunded_payment_id', $paymentId)
            ->where('type', 'refund')
            ->whereIn('status', ['pending', 'captured'])
            ->sum('amount_minor');
    }

    /** Pending → captured; anything else is left alone. */
    public static function markSucceeded(int $refundId, string $gatewayReference): void
    {
        DB::transaction(function () use ($refundId, $gatewayReference): void {
            $refund = Payment::query()->whereKey($refundId)->lockForUpdate()->first();
            if ($refund === null || $refund->status !== 'pending' || $refund->refunded_payment_id === null) {
                return;
            }

            $refund->update(['status' => 'captured', 'gateway_reference' => $gatewayReference, 'captured_at' => now()]);

            $original = Payment::query()->whereKey($refund->refunded_payment_id)->lockForUpdate()->firstOrFail();
            $refunded = (int) Payment::query()
                ->where('refunded_payment_id', $original->id)
                ->where('type', 'refund')
                ->where('status', 'captured')
                ->sum('amount_minor');
            $state = $refunded >= $original->amount_minor ? 'refunded' : 'part_refunded';

            $original->update(['status' => $state]);
            if ($original->order_id !== null) {
                Order::query()->whereKey($original->order_id)->update(['payment_status' => $state, 'updated_at' => now()]);
            }
        });
    }

    /** Pending → failed, with the gateway's reason. */
    public static function markFailed(int $refundId, string $reason): void
    {
        Payment::query()
            ->whereKey($refundId)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 500), 'updated_at' => now()]);
    }
}
