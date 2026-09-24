<?php

namespace App\Domain\Identity;

use App\Models\User;

/**
 * 07 §6.1 session limits (05.13 §13.1): idle 12 h for customers, 4 h for
 * staff, 1 h for admin; 7 days absolute for everyone. A user holding
 * several identities gets the strictest idle limit — an `admin` who is
 * also a trade buyer times out after an hour everywhere.
 */
final class SessionPolicy
{
    public const CUSTOMER_IDLE_SECONDS = 12 * 3600;

    public const STAFF_IDLE_SECONDS = 4 * 3600;

    public const ADMIN_IDLE_SECONDS = 3600;

    public const ABSOLUTE_SECONDS = 7 * 86400;

    public static function idleSecondsFor(User $user): int
    {
        /** @var list<string> $roles */
        $roles = $user->roles()->pluck('code')->all();

        return match (true) {
            in_array('admin', $roles, true) => self::ADMIN_IDLE_SECONDS,
            $roles !== [] => self::STAFF_IDLE_SECONDS,
            default => self::CUSTOMER_IDLE_SECONDS,
        };
    }
}
