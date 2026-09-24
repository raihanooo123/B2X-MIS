<?php

namespace App\Http\Support;

use App\Domain\Identity\LoginThrottle;
use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\Totp;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The sign-in sequence, 05.13 §6.1, shared by every way in: the sign-in
 * form, the 2FA challenge, and the automatic sign-in after a password
 * reset (which must not skip the second factor, §10).
 *
 *   1. lockout check (§6.2) before any credential is examined
 *   2–4. email + password + `status = 'active'`, one generic failure
 *   5. second factor, if enabled, before the session is authenticated
 *   6–7. Auth::login (fires `Login` → guest cart merge), session clocks,
 *        last_login_at, clear the identifier's failure count
 *
 * The company choice (§6.3) comes after this and is the caller's redirect.
 */
final class SignIn
{
    public const PENDING_2FA_USER = 'auth.2fa.user_id';

    public const PENDING_2FA_STARTED = 'auth.2fa.started_at';

    /** A password-verified sign-in waits this long for its second factor. */
    public const PENDING_2FA_SECONDS = 600;

    public const SIGNED_IN_AT = 'auth.signed_in_at';

    public const LAST_ACTIVITY_AT = 'auth.last_activity_at';

    /** Verified against when no user matches, so a miss costs what a hit does. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly LoginThrottle $throttle = new LoginThrottle,
    ) {}

    public function attempt(Request $request, string $email, string $password): SignInResult
    {
        $ip = (string) $request->ip();

        $locked = $this->throttle->lockedFor($ip, $email);
        if ($locked > 0) {
            return SignInResult::locked($locked);
        }

        $user = User::query()->where('email', $email)->first();
        $hash = $user?->password_hash;
        $passwordMatches = Hash::check($password, $hash ?? (self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16)))));

        if ($user === null || $hash === null || ! $passwordMatches || $user->status !== 'active') {
            $lock = $this->throttle->recordFailure($ip, $email);

            return $lock > 0 ? SignInResult::locked($lock) : SignInResult::failed();
        }

        if (Hash::needsRehash($hash)) {
            // Bcrypt → Argon2id on first sign-in after config/hashing.php (05.13 §19 Q15).
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
        }

        if ($user->two_factor_enabled) {
            $request->session()->put(self::PENDING_2FA_USER, $user->id);
            $request->session()->put(self::PENDING_2FA_STARTED, now()->getTimestamp());

            return SignInResult::twoFactorRequired();
        }

        $this->complete($request, $user);

        return SignInResult::signedIn();
    }

    /** The user awaiting a second factor, if the wait has not expired. */
    public function pendingTwoFactorUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_2FA_USER);
        $started = $request->session()->get(self::PENDING_2FA_STARTED);

        if (! is_int($id) || ! is_int($started) || now()->getTimestamp() - $started > self::PENDING_2FA_SECONDS) {
            return null;
        }

        $user = User::query()->find($id);

        return $user !== null && $user->status === 'active' && $user->two_factor_enabled ? $user : null;
    }

    /**
     * Second factor: a TOTP code or, failing that, a recovery code. Failed
     * codes count on the same lockout counters as passwords (05.13 §6.2).
     */
    public function completeTwoFactor(Request $request, User $user, string $code): SignInResult
    {
        $ip = (string) $request->ip();

        $locked = $this->throttle->lockedFor($ip, $user->email);
        if ($locked > 0) {
            return SignInResult::locked($locked);
        }

        $secret = $user->two_factor_secret;
        $valid = ($secret !== null && Totp::verify($secret, $code, 'user:'.$user->id))
            || RecoveryCodes::consume($user, $code);

        if (! $valid) {
            $lock = $this->throttle->recordFailure($ip, $user->email);

            return $lock > 0 ? SignInResult::locked($lock) : SignInResult::failed();
        }

        $this->complete($request, $user);

        return SignInResult::signedIn();
    }

    public function complete(Request $request, User $user): void
    {
        $request->session()->forget([self::PENDING_2FA_USER, self::PENDING_2FA_STARTED]);

        // Regenerates the session id, then fires `Login` (guest cart merge).
        Auth::login($user);
        $request->session()->regenerateToken();

        $now = now()->getTimestamp();
        $request->session()->put(self::SIGNED_IN_AT, $now);
        $request->session()->put(self::LAST_ACTIVITY_AT, $now);

        $user->forceFill(['last_login_at' => now()])->save();
        $this->throttle->clearIdentifier($user->email);
    }

    /**
     * Where a newly signed-in user goes (05.13 §6.1 steps 8–10): staff
     * without 2FA to enrolment (§12.1), a multi-company user to the
     * company choice (§6.3), everyone else back where they were heading.
     */
    public function redirectAfter(Request $request, User $user): RedirectResponse
    {
        if (! $user->two_factor_enabled && $user->isStaff()) {
            return redirect()->route('two-factor.setup');
        }

        if (ActingCompany::needsChoice($request->session(), $user)) {
            return redirect()->route('company.choose');
        }

        return redirect()->intended(route($user->isStaff() ? 'filament.admin.pages.dashboard' : 'order-pad'));
    }

    public function signOut(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
