<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Picking and dispatch (05.5 §5–7). Warehouse operatives do both; admin
 * too. A shipment is never deleted: once dispatched it is the delivery
 * record, and before that it is closed by dispatch or cancellation.
 */
final class ShipmentPolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    public const OPERATORS = ['admin', 'warehouse'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::OPERATORS);
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::OPERATORS);
    }

    /** Picking, short picks, substitutions and dispatch. */
    public function update(User $user, Shipment $shipment): bool
    {
        return $user->hasAnyRole(self::OPERATORS);
    }
}
