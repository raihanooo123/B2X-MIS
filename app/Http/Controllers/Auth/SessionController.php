<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Support\SignIn;
use App\Http\Support\SignInResult;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §6.1 — sign-in and sign-out, for everyone: public customers,
 * applicants, trade users and staff (the admin panel has no login page of
 * its own). The sequence itself is SignIn.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly SignIn $signIn = new SignIn,
    ) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $result = $this->signIn->attempt($request, $request->email(), $request->password());

        if ($result->is('two_factor_required')) {
            return redirect()->route('two-factor.challenge');
        }

        if ($result->is('signed_in')) {
            $user = Auth::user();
            assert($user instanceof User);

            return $this->signIn->redirectAfter($request, $user);
        }

        return back()->withErrors(['email' => self::failureMessage($result)])->onlyInput('email');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->signIn->signOut($request);

        return redirect()->route('order-pad');
    }

    public static function failureMessage(SignInResult $result): string
    {
        if (! $result->is('locked')) {
            return "Those details don't match an account.";
        }

        $minutes = (int) ceil($result->lockedSeconds / 60);

        return "Too many attempts. Please try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.';
    }
}
