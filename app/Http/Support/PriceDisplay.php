<?php

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Whether prices are shown excluding or including VAT (03 §10: stored
 * prices are always net; display is presentation only).
 *
 *   - Trade buyers: their company's `price_display_mode` (02 §4.3), `net`
 *     by default — trade prices are quoted ex-VAT.
 *   - Public customers and guests: `gross`. A consumer is shown the price
 *     they pay (07 §12, §16 Q10); they have no company to hold a setting.
 *
 * Changing the mode never changes what is charged — every total comes
 * from checkout preview either way.
 */
final class PriceDisplay
{
    /** @return 'net'|'gross' */
    public static function mode(Request $request): string
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession()) {
            return 'gross';
        }

        $company = ActingCompany::current($request->session(), $user);
        if ($company === null) {
            return 'gross';
        }

        return $company->price_display_mode === 'gross' ? 'gross' : 'net';
    }
}
