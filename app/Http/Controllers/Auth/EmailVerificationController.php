<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\EmailVerificationLink;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Auth\VerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §11. The link verifies the address it was sent to and nothing
 * else: it is not a sign-in link, so opening it on another device creates
 * no session.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('Auth/VerifyEmail', [
            'email' => $user->email,
            'verified' => $user->hasVerifiedEmail(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->hasVerifiedEmail()) {
            $user->notify(new VerifyEmail);
        }

        return back()->with('status', 'We have sent a new confirmation link.');
    }

    public function verify(Request $request, string $user, int $expires, string $signature): RedirectResponse
    {
        $target = User::query()->where('public_id', $user)->first();

        if ($target === null || ! EmailVerificationLink::isValid($target, $expires, $signature)) {
            return redirect()->route($request->user() ? 'verification.notice' : 'login')
                ->with('status', 'That confirmation link is invalid or has expired. Sign in to request a new one.');
        }

        if (! $target->hasVerifiedEmail()) {
            $target->forceFill(['email_verified_at' => now()])->save();
        }

        return $request->user()?->is($target)
            ? redirect()->route('order-pad')->with('status', 'Thank you — your email address is confirmed.')
            : redirect()->route('login')->with('status', 'Your email address is confirmed. Sign in to continue.');
    }
}
