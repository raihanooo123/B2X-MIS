<?php

namespace App\Domain\Storefront;

use App\Domain\Identity\CompanyMemberships;
use App\Models\User;

/** Same consumer classification as storefront pricing (05.13 §5.2). */
final class PublicCustomer
{
    public static function eligible(User $user): bool
    {
        return $user->status === 'active' && ! $user->isStaff() && CompanyMemberships::ids($user) === [];
    }
}
