<?php

namespace App\Policies;

use App\Models\CompanyUser;
use App\Models\Order;
use App\Models\User;

/**
 * Customer-side access to an order. A company order is visible to every
 * user on that company (05.2 §10 — viewers included); a public customer's
 * order only to them. Staff access goes through the admin panel's own
 * resources, not this.
 */
final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if ($order->company_id !== null) {
            return CompanyUser::query()
                ->where('company_id', $order->company_id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return $order->user_id === $user->id;
    }
}
