<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\BusinessVerification;
use App\Domain\Accounts\LegalForm;
use App\Domain\Accounts\TermsKind;
use App\Domain\Identity\BusinessType;
use App\Domain\Identity\Registration;
use App\Domain\Notifications\Notices\ApplicationAlreadyOpen;
use App\Domain\Notifications\Notices\ApplicationReapplyBlocked;
use App\Domain\Notifications\Notices\ApplicationSubmitted;
use App\Domain\Notifications\Notices\EmailVerification;
use App\Domain\Notifications\Notices\ExistingAccount;
use App\Domain\Notifications\Notifications;
use App\Domain\Notifications\Recipient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterPublicRequest;
use App\Http\Requests\Auth\RegisterTradeRequest;
use App\Jobs\VerifyApplicationBusiness;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
            'legal_forms' => LegalForm::choices(),
            'trade_terms' => $this->tradeTerms(),
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
        // member of staff entered, since no user holds the address yet. The
        // screen gives the normal confirmation and creates nothing; only the
        // address is told, by email (§5.1, no enumeration).
        $open = Registration::openApplicationForEmail($person['email']);
        if ($open !== null) {
            (new Notifications)->toRecipient(new ApplicationAlreadyOpen($open->id), new Recipient($person['email']));

            return $this->confirmation();
        }

        // 05.13 §7, 02 §25.2: a rejection on this address that is not
        // remediable holds a new application until its reapply_after. The
        // screen gives the normal confirmation — never the rejection or its
        // date — and the address alone is emailed the details (§5.1). A
        // remediable rejection holds nothing, so that filing goes ahead.
        $cooling = Registration::coolingRejectionForEmail($person['email']);
        if ($cooling !== null) {
            (new Notifications)->toRecipient(new ApplicationReapplyBlocked($cooling->id), new Recipient($person['email']));

            return $this->confirmation();
        }

        [$user, $application] = Registration::tradeApplicant($person, $request->application(), $request->acceptedTerms());
        // 02 §25.4–25.5: checked after commit, off the request. An outage is
        // recorded for the reviewer; the applicant never waits on it.
        if (BusinessVerification::checksFor($application) !== []) {
            VerifyApplicationBusiness::dispatch($application->id);
        }
        (new Notifications)->toUser(new EmailVerification($user->id), $user);
        (new Notifications)->toUser(new ApplicationSubmitted($application->id), $user);

        return $this->confirmation();
    }

    /**
     * 02 §25.1: the terms of trade in force, shown in full on the form.
     * The Markdown is rendered here with raw HTML escaped, so the page
     * never receives markup an administrator did not write as Markdown.
     *
     * @return array{id: int, version: string, html: string}|null
     */
    private function tradeTerms(): ?array
    {
        $terms = TermsVersion::current(TermsKind::Trade);

        return $terms === null ? null : [
            'id' => $terms->id,
            'version' => $terms->version,
            'html' => Str::markdown($terms->body_markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
        ];
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
