<?php

namespace App\Http\Controllers;

use App\Domain\Identity\CompanyMemberService;
use App\Domain\Identity\CompanyMemberSettings;
use App\Http\Requests\Company\ManageCompanyMemberRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class CompanyMemberController extends Controller
{
    public function update(ManageCompanyMemberRequest $request, Company $company, User $member, CompanyMemberService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $service->update($company, $member, $actor, CompanyMemberSettings::from($request->validated()));

        return redirect()->route('account.team')->with('status', 'Company member updated.');
    }

    public function destroy(ManageCompanyMemberRequest $request, Company $company, User $member, CompanyMemberService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $service->remove($company, $member, $actor);

        return redirect()->route('account.team')->with('status', 'Company member removed.');
    }
}
