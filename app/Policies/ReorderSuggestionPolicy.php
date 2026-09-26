<?php

namespace App\Policies;

use App\Models\ReorderSuggestion;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/** 05.7 §10.6: read-only, admin and purchasing — as Outstanding POs. */
final class ReorderSuggestionPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'purchasing']);
    }

    public function view(User $user, ReorderSuggestion $suggestion): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReorderSuggestion $suggestion): bool
    {
        return false;
    }
}
