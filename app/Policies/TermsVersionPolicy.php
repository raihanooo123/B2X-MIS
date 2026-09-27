<?php

namespace App\Policies;

use App\Models\TermsVersion;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * 02 §25.1: terms versions are published and viewed by an active
 * administrator only. Never updated or deleted — the table's trigger
 * refuses both — so the admin screen offers no such action.
 */
final class TermsVersionPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['admin']);
    }

    public function view(User $user, TermsVersion $terms): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, TermsVersion $terms): bool
    {
        return false;
    }
}
