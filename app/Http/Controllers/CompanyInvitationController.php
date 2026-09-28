<?php

namespace App\Http\Controllers;

use App\Domain\Identity\CompanyInvitationService;
use App\Domain\Identity\CompanyMemberSettings;
use App\Domain\Identity\CompanyMemberships;
use App\Http\Requests\Company\AcceptCompanyInvitationRequest;
use App\Http\Requests\Company\InviteCompanyMemberRequest;
use App\Http\Support\ActingCompany;
use App\Http\Support\SignIn;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanyInvitationController extends Controller
{
    public function store(InviteCompanyMemberRequest $request, Company $company, CompanyInvitationService $service): RedirectResponse
    {
        $service->invite($company, $this->user($request), (string) $request->validated('email'),
            (string) $request->validated('first_name'), (string) $request->validated('last_name'), CompanyMemberSettings::from($request->validated()));

        return back()->with('status', 'Invitation sent.');
    }

    public function resend(Request $request, CompanyInvitation $invitation, CompanyInvitationService $service): RedirectResponse
    {
        $service->resend($invitation, $this->user($request));

        return back()->with('status', 'A new invitation has been sent. The old link no longer works.');
    }

    public function revoke(Request $request, CompanyInvitation $invitation, CompanyInvitationService $service): RedirectResponse
    {
        $service->revoke($invitation, $this->user($request));

        return back()->with('status', 'Invitation revoked.');
    }

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->byToken($token);
        $decision = Gate::inspect('accept', $invitation);

        return Inertia::render('Auth/CompanyInvitation', [
            'invitation' => ['company' => $invitation->company->name, 'role' => $invitation->role],
            'signed_in' => $request->user() !== null,
            'refusal' => $decision->denied() ? $decision->message() : null,
            // Deliberately no user lookup, email, names, account-exists flag or token props.
            'action' => route('company-invitations.accept', ['token' => $token], false),
            'sign_in_url' => route('company-invitations.sign-in', ['token' => $token], false),
        ]);
    }

    public function signIn(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->byToken($token);
        abort_unless($invitation->isOpen(), 410, 'This invitation is no longer available.');
        $request->session()->put('url.intended', route('company-invitations.show', ['token' => $token]));

        return redirect()->route($request->user() === null ? 'login' : 'account');
    }

    public function accept(AcceptCompanyInvitationRequest $request, string $token, CompanyInvitationService $service, SignIn $signIn): RedirectResponse
    {
        $invitation = $this->byToken($token);
        $actor = $request->user();
        $user = $service->accept($invitation, $actor instanceof User ? $actor : null, $request->validated('password'));
        if ($user === null) {
            // A valid invitation is not a password-reset or a 2FA bypass.
            $request->session()->put('url.intended', route('company-invitations.show', ['token' => $token]));

            return redirect()->route('login')->with('status', 'Sign in to continue with your invitation.');
        }
        if ($actor === null) {
            $signIn->complete($request, $user);
        }

        return $this->accepted($request, $user);
    }

    public function acceptFromAccount(Request $request, CompanyInvitation $invitation, CompanyInvitationService $service): RedirectResponse
    {
        $user = $this->user($request);
        // Without the emailed token, require separately proven mailbox ownership.
        abort_unless($user->hasVerifiedEmail(), 403, 'Confirm your email address or use the emailed invitation link.');
        $service->accept($invitation, $user);

        return $this->accepted($request, $user);
    }

    private function accepted(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget('url.intended');
        if (count(CompanyMemberships::ids($user)) > 1) {
            ActingCompany::forget($request->session());
            $request->session()->put('url.intended', route('account'));

            return redirect()->route('company.choose')->with('status', 'Invitation accepted.');
        }

        return redirect()->route('account')->with('status', 'Invitation accepted. You can optionally set up two-factor authentication below.');
    }

    private function byToken(string $token): CompanyInvitation
    {
        return CompanyInvitation::query()->with('company')->where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
