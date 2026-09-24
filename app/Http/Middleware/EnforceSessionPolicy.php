<?php

namespace App\Http\Middleware;

use App\Domain\Identity\SessionPolicy;
use App\Http\Exceptions\ApiException;
use App\Http\Support\SignIn;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 07 §6.1 session limits (05.13 §13): per-identity idle timeout
 * (SessionPolicy, strictest wins) and the 7-day absolute maximum, from
 * two timestamps SignIn keeps in the session. Also ends the session of a
 * user who is no longer `active` (suspended, closed) on their next
 * request (05.13 §4.2).
 *
 * `session.lifetime` is the longest idle limit, so the framework never
 * expires a session before this middleware would. Expiry never touches
 * the cart, which is server-side (02 §14.3).
 */
final class EnforceSessionPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $signedInAt = $session->get(SignIn::SIGNED_IN_AT);
        $lastActivityAt = $session->get(SignIn::LAST_ACTIVITY_AT);

        if ($user->status !== 'active') {
            return $this->end($request, 'Your account is not active. Please contact us if you think this is wrong.');
        }

        if (! is_int($signedInAt)) {
            // A session authenticated without SignIn (Auth::login in a
            // console command or test): start its clocks now.
            $session->put(SignIn::SIGNED_IN_AT, $now);
        } elseif ($now - $signedInAt > SessionPolicy::ABSOLUTE_SECONDS
            || (is_int($lastActivityAt) && $now - $lastActivityAt > SessionPolicy::idleSecondsFor($user))) {
            return $this->end($request, 'Your session has expired. Please sign in again.');
        }

        $session->put(SignIn::LAST_ACTIVITY_AT, $now);

        return $next($request);
    }

    private function end(Request $request, string $message): Response
    {
        (new SignIn)->signOut($request);

        if ($request->is('api/*')) {
            return ApiException::envelope($request, 401, 'session_expired', $message);
        }

        $request->session()->flash('status', $message);

        return redirect()->guest(route('login'));
    }
}
