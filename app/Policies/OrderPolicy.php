<?php

namespace App\Policies;

use App\Models\CompanyUser;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Customer-side access to an order. A company order is visible to every
 * user on that company (05.2 §10 — viewers included); a public customer's
 * order only to them. Staff access goes through the admin panel's own
 * resources, not this.
 */
final class OrderPolicy
{
    public function view(User $user, Order $order): bool|Response
    {
        if ($order->company_id !== null) {
            return CompanyUser::query()
                ->where('company_id', $order->company_id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return $order->user_id === $user->id && $user->hasVerifiedEmail() ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * 05.4 §13.2: a consumer cancels their own order before dispatch. A
     * trade order is amended through 05.10, not here (05.15 §12 Q11).
     * Whether it can still be cancelled is OrderCancellationService's.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $order->company_id === null && $order->user_id === $user->id && $user->hasVerifiedEmail();
    }
}
