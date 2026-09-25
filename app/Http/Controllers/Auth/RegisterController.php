<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\BusinessType;
use App\Domain\Identity\Registration;
use App\Domain\Notifications\Notices\ApplicationSubmitted;
use App\Domain\Notifications\Notices\EmailVerification;
use App\Domain\Notifications\Notices\ExistingAccount;
use App\Domain\Notifications\Notifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterPublicRequest;
use App\Http\Requests\Auth\RegisterTradeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §5.1 (trade: apply and register in one step) and §5.2 (public).
 *
 * No enumeration: whether or not the address already has an account, the
 * visitor sees the same "check your inbox" confirmation and is **not**
 * signed in. The address's owner gets either the verification email or
 * an "you already have an account" email. Signing a new registrant in
 * straight away would reveal which case it was, so they sign in after
 * confirming their email — which also means the guest cart merges at
 * that sign-in (§8), exactly as for any other.
 */
class RegisterController extends Controller
{
    /** 06 §12's unauthenticated limit, per IP. */
    private const PER_MINUTE = 10;

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            'type' => $request->query('type') === 'public' ? 'public' : 'trade',
            'business_types' => array_map(
                fn (BusinessType $t) => ['value' => $t->value, 'label' => $t->label()],
                BusinessType::cases(),
            ),
        ]);
    }

    public function storePublic(RegisterPublicRequest $request): RedirectResponse
    {
        $this->throttle($request);
        $person = $request->person();

        $existing = User::query()->where('email', $person['email'])->first();
        if ($existing !== null) {
            (new Notifications)->toUser(new ExistingAccount($existing->id), $existing);

            return $this->confirmation();
        }

        $user = Registration::publicCustomer($person);
        (new Notifications)->toUser(new EmailVerification($user->id), $user);

        return $this->confirmation();
    }

    public function storeTrade(RegisterTradeRequest $request): RedirectResponse
    {
        $this->throttle($request);
        $person = $request->person();

        $existing = User::query()->where('email', $person['email'])->first();
        if ($existing !== null) {
            (new Notifications)->toUser(new ExistingAccount($existing->id), $existing);

            return $this->confirmation();
        }

        // 05.2 §5.2: an application already open on this address — one a
        // member of staff entered, since no user holds the address yet.
        if (Registration::hasOpenApplication($person['email'])) {
            return back()->withErrors(['email' => 'You already have an application in progress. We will be in touch.'])->withInput($request->except('password', 'password_confirmation'));
        }

        [$user, $application] = Registration::tradeApplicant($person, $request->application());
        (new Notifications)->toUser(new EmailVerification($user->id), $user);
        (new Notifications)->toUser(new ApplicationSubmitted($application->id), $user);

        return $this->confirmation();
    }

    private function confirmation(): RedirectResponse
    {
        return redirect()->route('login')->with('status', 'Thank you. Check your inbox — we have sent you an email. Once your address is confirmed, sign in here.');
    }

    private function throttle(Request $request): void
    {
        $key = 'auth:register:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, self::PER_MINUTE), 429, 'Too many attempts. Please wait a minute.');
        RateLimiter::hit($key, 60);
    }
}
