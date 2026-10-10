<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Accounts\ApplicantApplications;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\ApplicationReplyRequest;
use App\Http\Support\CartContext;
use App\Models\B2bApplication;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.17 §4 — the applicant's status page, reply and withdrawal. For the
 * signed-in applicant only (B2bApplicationPolicy::viewOwn): no company,
 * no trade shell, no financial data. Someone with no application sees a
 * plain "no application" state, never another person's.
 */
class ApplicationPageController extends Controller
{
    public function __construct(private readonly ApplicantApplications $applications = new ApplicantApplications) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $application = $this->applications->latestFor($user);
        if ($application !== null) {
            Gate::authorize('viewOwn', $application);
        }

        return Inertia::render('Trade/Application/Status', [
            // The storefront shell: an applicant has no trade shell yet (05.17 §1).
            'shell' => (new StorefrontShell)->props((new CartContext)->owner($request, createGuestToken: false)),
            'application' => $application === null ? null : $this->applications->status($application, $user),
            'limits' => [
                'reply_max' => ApplicantApplications::REPLY_MAX,
                'files_max' => ApplicantApplications::FILES_MAX,
                'file_max_mb' => intdiv(ApplicantApplications::FILE_MAX_KB, 1024),
            ],
        ]);
    }

    public function reply(ApplicationReplyRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $application = $this->own($user);
        Gate::authorize('replyOwn', $application);

        $this->applications->reply($application, $user, $request->reply(), $request->evidence());

        return redirect()->route('trade.application')->with('status', 'Thank you. Your reply has been sent and your application is back in review.');
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you want to withdraw the application.']);
        $user = $this->user($request);
        $application = $this->own($user);
        Gate::authorize('withdrawOwn', $application);

        $this->applications->withdraw($application, $user);

        return redirect()->route('trade.application')->with('status', 'Your application has been withdrawn.');
    }

    private function own(User $user): B2bApplication
    {
        return $this->applications->latestFor($user) ?? abort(404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
