<?php

namespace App\Policies;

use App\Models\PayAtCollectionSuspension;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * 05.6 §7A.11 — staff with the `accounts` role suspend pay at collection
 * by hand and lift suspensions, with a reason. History is never deleted.
 */
final class PayAtCollectionSuspensionPolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    private const ACCOUNTS = ['admin', 'accounts'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ACCOUNTS);
    }

    public function view(User $user, PayAtCollectionSuspension $suspension): bool
    {
        return $this->viewAny($user);
    }

    /** A manual suspension. */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::ACCOUNTS);
    }

    /** Lift. */
    public function update(User $user, PayAtCollectionSuspension $suspension): bool
    {
        return $suspension->lifted_at === null && $user->hasAnyRole(self::ACCOUNTS);
    }
}
