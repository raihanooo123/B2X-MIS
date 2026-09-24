<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\CompanyMemberships;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChooseCompanyRequest;
use App\Http\Support\ActingCompany;
use App\Http\Support\GuestCartMerge;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.13 §6.3 — which company a user in several companies acts for. Asked
 * after every sign-in, skipped when there is only one; switchable during
 * the session from the account menu. The first choice after sign-in also
 * merges the guest cart into that company's cart (§8.4).
 */
class CompanyChoiceController extends Controller
{
    public function __construct(
        private readonly GuestCartMerge $merge = new GuestCartMerge,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->user($request);
        $companies = CompanyMemberships::for($user);

        if ($companies->count() <= 1) {
            return redirect()->intended(route('order-pad'));
        }

        $current = ActingCompany::chosenId($request->session(), $user);

        return Inertia::render('Auth/ChooseCompany', [
            'companies' => $companies->map(fn (Company $c) => [
                'id' => $c->public_id,
                'name' => $c->name,
                'account_code' => $c->account_code,
                'suspended' => $c->status === 'suspended',
                'current' => $c->id === $current,
            ])->values()->all(),
        ]);
    }

    public function store(ChooseCompanyRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $company = CompanyMemberships::for($user)->firstWhere('public_id', $request->companyPublicId());

        if ($company === null) {
            return back()->withErrors(['company' => 'Choose one of your companies.']);
        }

        ActingCompany::choose($request->session(), $company);
        $this->merge->afterCompanyChoice($request->session(), $user);

        return redirect()->intended(route('order-pad'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
