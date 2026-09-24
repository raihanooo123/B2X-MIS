<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\Totp;
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
 * Enrolment is three steps, nothing persisted until the last:
 *   1. a new secret is held in the session and shown (key + otpauth link);
 *   2. the user proves their app has it with a code → recovery codes are
 *      generated and shown, still only in the session;
 *   3. the user confirms they have saved the codes → secret, flag and
 *      hashed codes are written in one transaction (§12.2: "enrolment is
 *      not complete until the user confirms they have saved them").
 */
class TwoFactorSetupController extends Controller
{
    private const SETUP_SECRET = 'auth.2fa.setup_secret';

    private const SETUP_CODES = 'auth.2fa.setup_codes';

    private const SHOW_CODES = 'auth.2fa.show_codes';

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $session = $request->session();

        if ($user->two_factor_enabled) {
            return Inertia::render('Auth/TwoFactorSetup', [
                'enabled' => true,
                'is_staff' => $user->isStaff(),
                'remaining_codes' => RecoveryCodes::remaining($user),
                'low_watermark' => RecoveryCodes::LOW_WATERMARK,
                'new_codes' => $session->get(self::SHOW_CODES),
            ]);
        }

        $secret = $session->get(self::SETUP_SECRET);
        if (! is_string($secret)) {
            $secret = Totp::generateSecret();
            $session->put(self::SETUP_SECRET, $secret);
        }

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
        $secret = $request->session()->get(self::SETUP_SECRET);

        if (! is_string($secret) || ! Totp::verify($secret, $request->code())) {
            return back()->withErrors(['code' => 'That code does not match. Check the time on your phone and try again.']);
        }

        $request->session()->put(self::SETUP_CODES, RecoveryCodes::generate());

        return redirect()->route('two-factor.setup');
    }

    public function complete(Request $request): RedirectResponse
    {
        $request->validate(['saved' => ['accepted']], ['saved.accepted' => 'Confirm you have saved your recovery codes.']);

        $user = $this->user($request);
        $secret = $request->session()->get(self::SETUP_SECRET);
        $codes = $request->session()->get(self::SETUP_CODES);

        if (! is_string($secret) || ! is_array($codes)) {
            return redirect()->route('two-factor.setup');
        }

        DB::transaction(function () use ($user, $secret, $codes) {
            $user->forceFill(['two_factor_secret' => $secret, 'two_factor_enabled' => true])->save();
            RecoveryCodes::replace($user, array_values(array_map('strval', $codes)));
        });

        $request->session()->forget([self::SETUP_SECRET, self::SETUP_CODES]);
        $request->session()->flash('status', 'Two-factor authentication is on.');

        return (new SignIn)->redirectAfter($request, $user);
    }

    public function regenerateCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->two_factor_enabled, 409);

        $codes = RecoveryCodes::generate();
        RecoveryCodes::replace($user, $codes);
        $request->session()->flash(self::SHOW_CODES, $codes);

        return redirect()->route('two-factor.setup');
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

        return redirect()->route('two-factor.setup')->with('status', 'Two-factor authentication is off.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
