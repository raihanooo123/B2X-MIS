<?php

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a user's sessions (05.13 §13.3; 07 §6.1: a password reset
 * "invalidates all sessions"). With the database session driver (02 §17.4)
 * that is one DELETE on `sessions.user_id`. With any other driver there
 * is no per-user index, and Laravel's AuthenticateSession middleware —
 * which notices the changed password hash on the next request — is the
 * only mechanism; it stays enabled either way.
 */
final class UserSessions
{
    public static function endAll(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
