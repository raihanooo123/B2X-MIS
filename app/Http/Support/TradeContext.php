<?php

namespace App\Http\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in trade user and the company they act for (05.13 §6.3),
 * for /trade pages and their /api/v1 actions. Company identity always
 * comes from the verified membership in the session, never the request
 * (06 §18). No acting company → 403.
 */
final class TradeContext
{
    /** @return array{0: User, 1: Company} */
    public static function resolve(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $company = $request->hasSession() ? ActingCompany::current($request->session(), $user) : null;
        abort_if($company === null, 403);

        return [$user, $company];
    }

    /** @return array{name: string, account_code: string} */
    public static function companyProps(Company $company): array
    {
        return ['name' => $company->name, 'account_code' => (string) $company->account_code];
    }
}
