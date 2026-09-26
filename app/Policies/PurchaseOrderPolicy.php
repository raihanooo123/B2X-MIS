<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

final class PurchaseOrderPolicy
{
    use DeniesDeletion;

    private const STAFF = ['admin', 'purchasing'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::STAFF);
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $this->viewAny($user) && $order->status === 'draft';
    }

    public function confirm(User $user, PurchaseOrder $order): bool
    {
        return $this->update($user, $order);
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $this->viewAny($user) && in_array($order->status, ['draft', 'confirmed', 'part_received'], true);
    }
}
