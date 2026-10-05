<?php

namespace App\Policies;

use App\Models\CmsPage;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * 05.11 §2.4: legal and help pages are edited and published by an active
 * administrator only (Q6). Pages are never created or deleted — the five
 * rows come from the migration — and a published version is never
 * edited (its table's trigger), so neither is offered.
 */
final class CmsPagePolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['admin']);
    }

    public function view(User $user, CmsPage $page): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ?CmsPage $page = null): bool
    {
        return $this->viewAny($user);
    }

    public function publish(User $user): bool
    {
        return $this->viewAny($user);
    }
}
