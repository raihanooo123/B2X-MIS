<?php

namespace App\Http\Middleware;

use App\Http\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 07 §6.1 / 05.13 §12.1: 2FA is mandatory for every staff role. A user
 * holding any `role_user` row without 2FA enabled reaches nothing but
 * enrolment and sign-out — including the storefront, since the stricter
 * posture wins for a user with several identities (05.13 §4.1). Granting
 * a staff role mid-session takes effect on the next request.
 */
final class RequireStaffTwoFactor
{
    /** Route names a staff user without 2FA may still reach. */
    public const ALLOWED = ['two-factor.setup*', 'logout', 'verification.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->two_factor_enabled || $request->routeIs(...self::ALLOWED) || ! $user->isStaff()) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return ApiException::envelope($request, 403, 'two_factor_enrolment_required', 'Set up two-factor authentication to continue.');
        }

        return redirect()->route('two-factor.setup');
    }
}
