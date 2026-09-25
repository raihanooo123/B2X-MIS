<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\Totp;
use App\Domain\Notifications\Notices\TwoFactorChanged;
use App\Domain\Notifications\Notifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Http\Support\SignIn;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §12 — enrolling in, and managing, 2FA. Mandatory for staff
 * (RequireStaffTwoFactor sends them here), optional for everyone else.
 *
 * Enrolment is three steps; 2FA is not on until the last:
 *   1. a secret is generated once and kept on the user
 *      (`users.two_factor_secret`, encrypted) with `two_factor_enabled`
 *      still false, and shown as a key and an otpauth link;
 *   2. the user proves their app has it with a code → recovery codes are
 *      generated and shown, held only in the session;
 *   3. the user confirms they have saved the codes → the flag and the
 *      hashed codes are written in one transaction (§12.2: "enrolment is
 *      not complete until the user confirms they have saved them").
 *
 * The pending secret lives on the user, not the session, on purpose. A
 * session-held secret was replaced by a new one whenever a new session
 * reached this page — signing in again, an idle timeout (1 h for admin),
 * a reset ending sessions, another browser — while the authenticator
 * (Apple Passwords, which also autofills from its first entry for the
 * site) kept the key it was given: every code, fresh ones included, then
 * "did not match". The key now changes only when the user asks for a new
 * one (reset()).
 */
class TwoFactorSetupController extends Controller
{
    private const SETUP_CODES = 'auth.2fa.setup_codes';

    /** New recovery codes awaiting the user's "I have saved these" (read by AccountController). */
    public const REGENERATED_CODES = 'auth.2fa.regenerated_codes';

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->user($request);
        $session = $request->session();

        // Enrolment only. Once 2FA is on, it is managed from the account
        // page (recovery codes, turning it off).
        if ($user->two_factor_enabled) {
            return redirect()->route('account');
        }

        $secret = $this->pendingSecret($user);

        return Inertia::render('Auth/TwoFactorSetup', [
            'enabled' => false,
            'is_staff' => $user->isStaff(),
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email, (string) config('app.name')),
            'pending_codes' => $session->get(self::SETUP_CODES),
        ]);
    }

    public function confirm(TwoFactorCodeRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $secret = $user->two_factor_enabled ? null : $user->two_factor_secret;

        if ($secret === null || ! Totp::verify($secret, $request->code())) {
            return back()->withErrors(['code' => 'That code does not match. If you have added this account to your app more than once, use the newest entry — or start again with a new key.']);
        }

        $request->session()->put(self::SETUP_CODES, RecoveryCodes::generate());

        return redirect()->route('two-factor.setup');
    }

    public function complete(Request $request): RedirectResponse
    {
        $request->validate(['saved' => ['accepted']], ['saved.accepted' => 'Confirm you have saved your recovery codes.']);

        $user = $this->user($request);
        $codes = $request->session()->get(self::SETUP_CODES);

        if ($user->two_factor_secret === null || ! is_array($codes)) {
            return redirect()->route('two-factor.setup');
        }

        DB::transaction(function () use ($user, $codes) {
            $user->forceFill(['two_factor_enabled' => true])->save();
            RecoveryCodes::replace($user, array_values(array_map('strval', $codes)));
        });

        (new Notifications)->toUser(new TwoFactorChanged($user->id, TwoFactorChanged::ENABLED), $user);

        $request->session()->forget(self::SETUP_CODES);
        $request->session()->flash('status', 'Two-factor authentication is on.');

        return (new SignIn)->redirectAfter($request, $user);
    }

    /**
     * Regenerating recovery codes, step 1 (after re-entering the password):
     * a new set is generated and shown, but held only in the session. The
     * current codes keep working until the user confirms they have saved
     * the new ones (confirmRegeneratedCodes) — so nobody can end up with
     * codes they never saw, the way a one-step regenerate allowed.
     */
    public function regenerateCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->two_factor_enabled, 409);

        $request->session()->put(self::REGENERATED_CODES, RecoveryCodes::generate());

        return redirect()->route('account');
    }

    /** Step 2: the user ticked that they saved the new set → it replaces the old one. */
    public function confirmRegeneratedCodes(Request $request): RedirectResponse
    {
        $request->validate(['saved' => ['accepted']], ['saved.accepted' => 'Tick to confirm you have saved your new recovery codes.']);

        $user = $this->user($request);
        $codes = $request->session()->get(self::REGENERATED_CODES);
        if (! $user->two_factor_enabled || ! is_array($codes)) {
            return redirect()->route('account');
        }

        RecoveryCodes::replace($user, array_values(array_map('strval', $codes)));
        $request->session()->forget(self::REGENERATED_CODES);

        return redirect()->route('account')->with('status', 'Your new recovery codes are active. The old ones no longer work.');
    }

    /** Changed their mind: the new set is discarded; the current codes stay. */
    public function cancelRegeneratedCodes(Request $request): RedirectResponse
    {
        $request->session()->forget(self::REGENERATED_CODES);

        return redirect()->route('account')->with('status', 'Your existing recovery codes are unchanged.');
    }

    /** Optional for trade users only; staff cannot turn it off (07 §6.1). */
    public function destroy(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_if($user->isStaff(), 403, 'Two-factor authentication is required for staff accounts.');

        DB::transaction(function () use ($user) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_enabled' => false])->save();
            $user->recoveryCodes()->delete();
        });

        (new Notifications)->toUser(new TwoFactorChanged($user->id, TwoFactorChanged::DISABLED), $user);

        $request->session()->forget(self::REGENERATED_CODES);

        return redirect()->route('account')->with('status', 'Two-factor authentication is off.');
    }

    /**
     * Start enrolment again with a new key — the only way the pending key
     * changes. The page tells the user to replace the entry in their app.
     */
    public function reset(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_if($user->two_factor_enabled, 409);

        $user->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();
        $request->session()->forget(self::SETUP_CODES);

        return redirect()->route('two-factor.setup')->with('status', 'Here is a new key. Remove the old entry from your authenticator app and add this one.');
    }

    /** The key being enrolled: generated once, then the same on every visit, session and device. */
    private function pendingSecret(User $user): string
    {
        if ($user->two_factor_secret === null) {
            $user->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();
        }

        return (string) $user->two_factor_secret;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
