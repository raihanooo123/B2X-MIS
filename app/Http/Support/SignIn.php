<?php

namespace App\Http\Support;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\CompanyMemberships;
use App\Domain\Identity\CompanyUserDirectory;
use App\Domain\Identity\LoginThrottle;
use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\Totp;
use App\Domain\Notifications\Notices\TwoFactorChanged;
use App\Domain\Notifications\Notifications;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
 * Every outcome is audited (§15): a failure and the lockout it may start
 * as a keyed fingerprint of the identifier, a success and a sign-out
 * against the user, with how they signed in or why the session ended.
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
        private readonly AuditLogger $audit = new AuditLogger,
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
            return $this->failed($request, $email);
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
        $totpValid = $secret !== null && Totp::verify($secret, $code, 'user:'.$user->id);
        $recoveryUsed = ! $totpValid && RecoveryCodes::consume($user, $code);

        if (! $totpValid && ! $recoveryUsed) {
            return $this->failed($request, $user->email);
        }

        $this->complete($request, $user, $recoveryUsed ? 'recovery_code' : 'two_factor');

        if ($recoveryUsed) {
            // 05.13 §12: using a recovery code is audited and notified.
            $this->audit->ownAuthEvent(AuditAction::RecoveryCodeUsed, $user->id, ['remaining' => RecoveryCodes::remaining($user)]);
            (new Notifications)->toUser(new TwoFactorChanged($user->id, TwoFactorChanged::RECOVERY_CODE_USED), $user);
        }

        return SignInResult::signedIn();
    }

    /**
     * @param  'password'|'two_factor'|'recovery_code'|'invitation'  $method  how the user proved who they are, for the audit
     */
    public function complete(Request $request, User $user, string $method = 'password'): void
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

        $this->audit->ownAuthEvent(AuditAction::SignedIn, $user->id, ['method' => $method]);
    }

    /**
     * Where a newly signed-in user goes (05.13 §6.1 steps 8–10): staff
     * without 2FA to enrolment (§12.1), staff with it to the panel, a
     * multi-company user to the company choice (§6.3), everyone else back
     * where they were heading.
     */
    public function redirectAfter(Request $request, User $user): Response
    {
        if (! $user->two_factor_enabled && $user->isStaff()) {
            return redirect()->route('two-factor.setup');
        }

        if ($user->isStaff()) {
            return $this->toPanel($request);
        }

        if (! $request->session()->has('url.intended') && CompanyUserDirectory::pending($user) !== []) {
            $request->session()->put('url.intended', route('account'));
        }

        if (ActingCompany::needsChoice($request->session(), $user)) {
            return redirect()->route('company.choose');
        }

        // 05.13 §6.1 step 10: a trade user's tool is the order pad; a public
        // customer or applicant shops the storefront.
        return redirect()->intended(route(CompanyMemberships::ids($user) !== [] ? 'order-pad' : 'home'));
    }

    /**
     * Staff land in the panel (05.13 §5): the /admin page they were sent
     * away from, else the dashboard. Any other intended URL is a storefront
     * page left over from browsing before signing in, and is dropped. The
     * panel is Filament, not an Inertia page, so an Inertia sign-in form
     * must do a full page visit to it rather than follow a redirect.
     */
    private function toPanel(Request $request): Response
    {
        $panel = route('filament.admin.pages.dashboard');
        $intended = $request->session()->pull('url.intended');

        $target = is_string($intended) && ($intended === $panel || Str::startsWith($intended, rtrim($panel, '/').'/'))
            ? $intended
            : $panel;

        return Inertia::location($target);
    }

    /**
     * Ends the session. `$expired` is why the system ended it
     * (EnforceSessionPolicy); null is the user signing out.
     *
     * @param  'idle'|'absolute'|'account_inactive'|null  $expired
     */
    public function signOut(Request $request, ?string $expired = null): void
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            $expired === null
                ? $this->audit->ownAuthEvent(AuditAction::SignedOut, $user->id)
                : $this->audit->ownAuthEvent(AuditAction::SessionExpired, $user->id, ['reason' => $expired]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** A wrong password or code: counted, audited, and a lockout it starts audited too (§6.2). */
    private function failed(Request $request, string $identifier): SignInResult
    {
        $ip = (string) $request->ip();
        $lock = $this->throttle->recordFailure($ip, $identifier);
        $this->audit->failedSignIn($identifier, $ip, $request->userAgent());

        if ($lock > 0) {
            $this->audit->lockedOut($identifier, $lock, $ip, $request->userAgent());

            return SignInResult::locked($lock);
        }

        return SignInResult::failed();
    }
}
