<?php

namespace App\Policies;

use App\Models\ReorderSetting;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/** 05.7 §10.7: admin and purchasing view and set reorder levels. */
final class ReorderSettingPolicy
{
    use DeniesDeletion;

    private const STAFF = ['admin', 'purchasing'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::STAFF);
    }

    public function view(User $user, ReorderSetting $setting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Class-level as well, so the suggestions page can offer the same edit. */
    public function update(User $user, ?ReorderSetting $setting = null): bool
    {
        return $user->hasAnyRole(self::STAFF);
    }
}
