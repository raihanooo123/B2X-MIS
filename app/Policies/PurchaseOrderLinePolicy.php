<?php

namespace App\Policies;

use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

final class PurchaseOrderLinePolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'purchasing']);
    }

    public function view(User $user, PurchaseOrderLine $line): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PurchaseOrderLine $line): bool
    {
        return false;
    }
}
