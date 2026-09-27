<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

final class AuditLogPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin']);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
