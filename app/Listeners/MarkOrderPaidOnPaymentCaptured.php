<?php

namespace App\Listeners;

use App\Domain\Billing\Events\PaymentCaptured;
use App\Models\Order;

/**
 * Ordering's side of a captured card payment: the order is now `paid`.
 * Only an `unpaid` order moves, so a repeat event changes nothing.
 */
final class MarkOrderPaidOnPaymentCaptured
{
    public function handle(PaymentCaptured $event): void
    {
        if ($event->orderId === null) {
            return;
        }

        Order::query()
            ->whereKey($event->orderId)
            ->where('payment_status', 'unpaid')
            ->update(['payment_status' => 'paid', 'updated_at' => now()]);
    }
}
