<?php

namespace App\Domain\Collection;

use App\Models\CollectionBooking;
use App\Models\CollectionSlot;

/**
 * A collection order's booking, on the paths that act on an existing order
 * (05.6 §7A.4): `orders` is already locked by the caller, then the one
 * `collection_slots` row, then the `collection_bookings` row — before any
 * shipment, allocation or stock lock.
 */
final class CollectionBookings
{
    public function __construct(private readonly CollectionSlots $slots = new CollectionSlots) {}

    /**
     * Locks the order's slot, then its booking. Null for an order with no booking.
     *
     * @return array{slot: CollectionSlot, booking: CollectionBooking}|null
     */
    public function lockForOrder(int $orderId): ?array
    {
        $slotId = CollectionBooking::query()->where('order_id', $orderId)->value('collection_slot_id');
        if ($slotId === null) {
            return null;
        }
        $slot = CollectionSlot::query()->where('id', $slotId)->lockForUpdate()->firstOrFail();
        // The booking cannot move slot without the order lock the caller holds.
        $booking = CollectionBooking::query()->where('order_id', $orderId)->lockForUpdate()->firstOrFail();

        return ['slot' => $slot, 'booking' => $booking];
    }

    /**
     * §7A.7: the order is cancelled before collection. A live booking
     * becomes `cancelled` and gives its place back to the slot. A booking
     * already marked `no_show` keeps the slot's count: the slot has passed
     * and its capacity was used (§7A.5 step 2).
     *
     * @param  array{slot: CollectionSlot, booking: CollectionBooking}|null  $locked
     */
    public function cancelLocked(?array $locked): void
    {
        if ($locked === null || $locked['booking']->status !== 'booked') {
            return;
        }
        $locked['booking']->forceFill(['status' => 'cancelled'])->save();
        $this->slots->releaseLocked($locked['slot']);
    }
}
