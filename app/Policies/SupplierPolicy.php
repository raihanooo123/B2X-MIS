<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

final class SupplierPolicy
{
    use DeniesDeletion;

    private const STAFF = ['admin', 'purchasing'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::STAFF);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }
}
