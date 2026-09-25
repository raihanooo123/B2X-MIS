<?php

namespace App\Policies;

use App\Models\NotificationLog;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Staff read access to the notification log (05.12 §10.3, AC7). Nobody
 * edits it: rows are written by the dispatcher and provider webhooks, and
 * removed only by the retention job.
 */
final class NotificationLogPolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    public const VIEWERS = ['admin', 'accounts'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::VIEWERS);
    }

    public function view(User $user, NotificationLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NotificationLog $log): bool
    {
        return false;
    }
}
