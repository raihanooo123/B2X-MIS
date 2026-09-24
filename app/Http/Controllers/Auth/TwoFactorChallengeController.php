<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Http\Support\SignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §12.3 — the second step of sign-in. The password was right; the
 * session is not authenticated until a TOTP or recovery code is. The wait
 * expires after SignIn::PENDING_2FA_SECONDS.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly SignIn $signIn = new SignIn,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->signIn->pendingTwoFactorUser($request) === null) {
            return redirect()->route('login')->with('status', 'Please sign in again.');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(TwoFactorCodeRequest $request): RedirectResponse
    {
        $user = $this->signIn->pendingTwoFactorUser($request);
        if ($user === null) {
            return redirect()->route('login')->with('status', 'That took too long. Please sign in again.');
        }

        $result = $this->signIn->completeTwoFactor($request, $user, $request->code());

        if ($result->is('signed_in')) {
            return $this->signIn->redirectAfter($request, $user);
        }

        return back()->withErrors([
            'code' => $result->is('locked') ? SessionController::failureMessage($result) : 'That code is not valid.',
        ]);
    }
}
