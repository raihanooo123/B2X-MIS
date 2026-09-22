<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "Nobody gets delete — archiving via status comes later." One place
 * for that rule across every catalogue Policy, so it can't drift: a
 * Policy that composes this trait and forgets to override one of these
 * methods still denies correctly, rather than silently allowing.
 */
trait DeniesDeletion
{
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
