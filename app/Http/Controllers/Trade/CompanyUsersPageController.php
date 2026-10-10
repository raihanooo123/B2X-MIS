<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Identity\CompanyUserDirectory;
use App\Http\Controllers\Controller;
use App\Http\Support\TradeContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.2 §18.3 — company users: role, per-order limit and approval flag,
 * edited in place through PATCH /api/v1/company-users/{user_id}. Owners
 * of the acting company (CompanyPolicy::manageMembers).
 */
class CompanyUsersPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        $management = CompanyUserDirectory::management($user, $company);
        abort_if($management === null, 403);

        return Inertia::render('Trade/Account/Users', [
            'company' => TradeContext::companyProps($company),
            'members' => $management['members'],
        ]);
    }
}
