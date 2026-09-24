<?php

namespace App\Domain\Ordering;

use App\Models\Order;

/**
 * Ordering's half of a payment being taken: the order becomes `paid`.
 *
 * Called by Billing inside the same transaction that marks the payment
 * captured (CardPayments::markCaptured), so "the card was charged" and
 * "the order is paid" commit together or not at all. This used to be an
 * after-commit event listener; a stale event cache (`event:cache` taken
 * before the listener existed — never run it here, CLAUDE.md) left it
 * unregistered, and card orders charged at Stripe stayed `unpaid`: a
 * reconciliation failure. Money state is not left to listener discovery.
 *
 * Only an `unpaid` order moves, so repeating it changes nothing.
 */
final class OrderPayments
{
    /** @return bool whether the order moved to `paid` */
    public static function markPaid(int $orderId): bool
    {
        return Order::query()
            ->whereKey($orderId)
            ->where('payment_status', 'unpaid')
            ->update(['payment_status' => 'paid', 'updated_at' => now()]) === 1;
    }
}
