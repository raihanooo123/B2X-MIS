<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Notifications\Notices\PasswordChanged;
use App\Domain\Notifications\Notices\PasswordReset as PasswordResetNotice;
use App\Domain\Notifications\Notifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Support\SignIn;
use App\Http\Support\UserSessions;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §10 — password reset, and the "set your password" link for a new
 * staff user (§5.3), on Laravel's broker and its `password_reset_tokens`
 * table (02 §17.3): single use, 60 minutes (07 §6.1).
 *
 * The request never says whether the address has an account. Resetting
 * deletes every other session of the user, then signs in on this device
 * through the normal sequence — including the 2FA challenge; a reset
 * never bypasses the second factor.
 */
class PasswordResetController extends Controller
{
    /** 06 §12: 5 per minute per IP and per identifier. */
    private const PER_MINUTE = 5;

    private const GENERIC = 'If that address has an account, we have sent a link to reset the password.';

    public function __construct(
        private readonly SignIn $signIn = new SignIn,
    ) {}

    public function requestForm(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => $request->session()->get('status')]);
    }

    public function sendLink(ForgotPasswordRequest $request): RedirectResponse
    {
        $email = $request->email();
        $keys = ['auth:reset:ip:'.$request->ip(), 'auth:reset:id:'.hash('sha256', mb_strtolower($email))];
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
                return back()->withErrors(['email' => 'Too many requests. Please wait a minute and try again.']);
            }
        }
        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }

        $user = User::query()->where('email', $email)->whereIn('status', ['active', 'pending'])->first();
        $tokens = $this->tokens();

        if ($user !== null && ! $tokens->recentlyCreatedToken($user)) {
            (new Notifications)->toUser(new PasswordResetNotice($user->id, $tokens->create($user)), $user);
        }

        return back()->with('status', self::GENERIC);
    }

    public function resetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(ResetPasswordRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');
        $tokens = $this->tokens();

        $user = User::query()->where('email', $email)->whereIn('status', ['active', 'pending'])->first();

        if ($user === null || ! $tokens->exists($user, (string) $request->validated('token'))) {
            return back()->withErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.']);
        }

        DB::transaction(function () use ($user, $password, $tokens) {
            $changes = ['password_hash' => Hash::make($password)];
            if ($user->status === 'pending') {
                // A new staff user setting their first password (05.13 §5.3).
                $changes += ['status' => 'active', 'email_verified_at' => now()];
            }
            $user->forceFill($changes)->save();
            $tokens->delete($user);
        });

        UserSessions::endAll($user);
        event(new PasswordReset($user));
        (new Notifications)->toUser(new PasswordChanged($user->id), $user);

        $result = $this->signIn->attempt($request, $email, $password);

        return match (true) {
            $result->is('two_factor_required') => redirect()->route('two-factor.challenge'),
            $result->is('signed_in') => $this->signIn->redirectAfter($request, $user),
            default => redirect()->route('login')->with('status', 'Your password has been changed. Please sign in.'),
        };
    }

    private function tokens(): TokenRepositoryInterface
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker();

        return $broker->getRepository();
    }
}
