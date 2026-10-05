<?php

namespace App\Policies;

use App\Models\CollectionSlot;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * 05.6 §7A.1 — slots are generated, never created by hand or deleted.
 * Warehouse staff close and reopen them (a closure, or a whole day);
 * accounts may look.
 */
final class CollectionSlotPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'warehouse', 'accounts']);
    }

    public function view(User $user, CollectionSlot $slot): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Close or reopen. */
    public function update(User $user, CollectionSlot $slot): bool
    {
        return $user->hasAnyRole(['admin', 'warehouse']);
    }
}
