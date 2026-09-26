<?php

namespace App\Policies;

use App\Models\Stocktake;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Stocktakes (05.5 §8). The warehouse counts and posts. Purchasing and
 * accounts see them — a posted stocktake moves stock value — through the
 * read-only Filament resource. Nobody deletes one: a posted stocktake is
 * the record behind its movements, and an abandoned one is cancelled.
 */
final class StocktakePolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    public const COUNTERS = ['admin', 'warehouse'];

    /** @var list<string> */
    public const VIEWERS = ['admin', 'warehouse', 'purchasing', 'accounts'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::VIEWERS);
    }

    public function view(User $user, Stocktake $stocktake): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::COUNTERS);
    }

    /** Counting, review, posting and cancelling. */
    public function update(User $user, Stocktake $stocktake): bool
    {
        return $user->hasAnyRole(self::COUNTERS);
    }
}
