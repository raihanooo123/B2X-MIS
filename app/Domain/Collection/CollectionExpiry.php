<?php

namespace App\Domain\Collection;

use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\OrderCancellationService;
use App\Domain\Ordering\PaymentMethod;
use App\Models\CollectionBooking;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §7A.5 — `collection:expire-unpaid`, every 5 minutes, one worker.
 *
 * For each live booking whose `payment_due_by` has passed
 * (`collection_bookings_payment_due_idx`), in its own transaction:
 *
 *   1. `orders` FOR UPDATE, then the booking. Re-check: still unpaid, still
 *      booked, still past the deadline. Otherwise skip — payment won.
 *   2. Booking → `no_show`, `no_show_at`. The slot keeps its count: it is
 *      in the past and its capacity was used.
 *   3. The order cancelled (OrderCancellationService, `collection_expired`):
 *      `deallocation` movements, staged shipments cancelled, nothing to
 *      refund.
 *   4. The customer's no-shows counted; at the limit, a suspension.
 *   5. `collection.expired`, and `collection.pay_at_collection_suspended`
 *      when suspended — queued, sent after commit.
 *
 * Recording cash locks the same `orders` row first, so the counter and the
 * sweep serialise on it: payment is accepted right up to the sweep.
 */
final class CollectionExpiry
{
    public function __construct(
        private readonly OrderCancellationService $cancellations = new OrderCancellationService,
        private readonly PayAtCollectionSuspensions $suspensions = new PayAtCollectionSuspensions,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /** @return int orders expired */
    public function sweep(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $orderIds = CollectionBooking::query()
            ->where('status', 'booked')
            ->whereNotNull('payment_due_by')
            ->where('payment_due_by', '<', $now)
            ->orderBy('payment_due_by')
            ->pluck('order_id');

        $expired = 0;
        foreach ($orderIds as $orderId) {
            $expired += (new DeadlockRetryPolicy)->run(
                fn () => DB::transaction(fn () => $this->expire((int) $orderId, $now)),
                self::class,
            ) ? 1 : 0;
        }

        return $expired;
    }

    private function expire(int $orderId, CarbonImmutable $now): bool
    {
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->firstOrFail();
        $booking = CollectionBooking::query()->where('order_id', $orderId)->lockForUpdate()->first();

        if ($booking === null || $booking->status !== 'booked' || $booking->payment_due_by === null
            || ! $booking->payment_due_by->lessThan($now)
            || $order->payment_method !== PaymentMethod::CashAtCollection->value
            || $order->payment_status !== 'unpaid'
            || ! in_array($order->status, ['confirmed', 'picking'], true)) {
            return false;
        }

        $booking->forceFill(['status' => 'no_show', 'no_show_at' => $now])->save();
        $this->cancellations->cancelExpiredCollection($order->id);
        $suspension = $this->suspensions->afterNoShow($order, $now);

        $this->notifications->collectionExpired($order->id);
        if ($suspension !== null) {
            $this->notifications->payAtCollectionSuspended($suspension['id'], $order->company_id === null ? $order->user_id : null, $order->company_id, $suspension['count']);
        }

        return true;
    }
}
