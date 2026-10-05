<?php

namespace App\Policies;

use App\Models\CollectionBooking;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * 05.6 §7A.6 — the Collections counter. Warehouse operatives (the
 * `dispatch` permission, as ShipmentPolicy) identify the collector, take
 * the cash and hand over; `accounts` see the counter and alone may void a
 * wrongly keyed cash payment. §7A.6a: the daily cash report is for
 * `accounts` or `dispatch`. Slots and suspensions have their own policies.
 */
final class CollectionBookingPolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    private const COUNTER = ['admin', 'warehouse'];

    /** @var list<string> */
    private const ACCOUNTS = ['admin', 'accounts'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...self::COUNTER, 'accounts']);
    }

    public function view(User $user, CollectionBooking $booking): bool
    {
        return $this->viewAny($user);
    }

    /** Record cash and hand over. */
    public function serve(User $user): bool
    {
        return $user->hasAnyRole(self::COUNTER);
    }

    public function voidCash(User $user): bool
    {
        return $user->hasAnyRole(self::ACCOUNTS);
    }

    public function viewCashReport(User $user): bool
    {
        return $user->hasAnyRole([...self::COUNTER, 'accounts']);
    }
}
