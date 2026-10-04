<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Notifications\Notifications;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Makes pending card refunds at the gateway — always **after** the
 * transaction that recorded them has committed, never inside it (CLAUDE.md
 * invariant 6). A consumer is refunded to the card they paid with (CCR reg.
 * 34(7), 05.4 §13.6).
 *
 *   - success → Refunds::markSucceeded (refund `captured`, original and
 *     order `refunded` or `part_refunded`);
 *   - the gateway refuses (an expired card) → Refunds::markFailed and
 *     `refund.failed` to accounts, who repay by bank transfer before the
 *     deadline (05.4 §13.6). Never an account balance: a consumer has none.
 *   - a BACS refund is left pending for accounts to pay and record.
 *
 * Shared by a cancellation before dispatch (05.4 §13.2) and a resolved
 * return (§13.6).
 */
final class RefundSettlement
{
    public function __construct(
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /**
     * @param  list<int>  $refundIds  pending `refund` rows on `payments`
     */
    public function settle(array $refundIds): void
    {
        foreach ($refundIds as $refundId) {
            $refund = Payment::query()->find($refundId);
            $original = $refund?->refunded_payment_id === null ? null : Payment::query()->find($refund->refunded_payment_id);
            if ($refund === null || $original === null || $refund->status !== 'pending' || $refund->gateway !== 'stripe' || $original->gateway_reference === null) {
                continue; // BACS: accounts repay by transfer and record it.
            }

            try {
                $reference = $this->gateway()->refund($original->gateway_reference, $refund->amount_minor, 'refund:'.$refund->public_id);
                Refunds::markSucceeded($refund->id, $reference);
            } catch (PaymentGatewayException $e) {
                Refunds::markFailed($refund->id, $e->getMessage());
                Log::error('Card refund failed; accounts notified to repay by bank transfer.', ['refund' => $refund->id, 'error' => $e->getMessage()]);
                $this->notifications->refundFailed($refund->id);
            }
        }
    }

    /** Resolved on use, so settling no card refunds never builds a Stripe client. */
    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }
}
