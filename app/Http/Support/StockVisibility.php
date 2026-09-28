<?php

namespace App\Http\Support;

use App\Domain\Identity\CompanyMemberships;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who sees exact stock figures (05.13 §14, 06 §9.4, 05.15 §12 Q2): trade
 * users, acting for a company, and staff. Everyone else (guests, public
 * customers, applicants) sees a label only, because exact figures would
 * show a competitor the stock position.
 */
final class StockVisibility
{
    public static function exactFigures(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }
        if ($user->isStaff()) {
            return true;
        }

        // A stateless API call has no acting company in a session: any
        // active membership makes the caller a trade user.
        return $request->hasSession()
            ? ActingCompany::current($request->session(), $user) !== null
            : CompanyMemberships::ids($user) !== [];
    }
}
