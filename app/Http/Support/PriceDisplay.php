<?php

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Whether prices are shown excluding or including VAT (03 §10: stored
 * prices are always net; display is presentation only). 05.15 §4.1:
 *
 *   - Trade buyers: their company's `price_display_mode` (02 §4.3), `net`
 *     by default — trade prices are quoted ex-VAT. No switch.
 *   - Staff: `net`. No switch.
 *   - Public customers and guests: `gross` — a consumer is shown the price
 *     they pay (Price Marking Order 2004) — with an *Ex VAT* switch for the
 *     trade buyer browsing before they sign in, remembered in the
 *     first-party `price_display` cookie (a preference the visitor set, so
 *     strictly necessary under PECR, 05.15 §8.2).
 *
 * Changing the mode never changes what is charged — every total comes
 * from checkout preview either way.
 */
final class PriceDisplay
{
    public const COOKIE = 'price_display';

    /** One year, in minutes (Cookie::make's unit). */
    public const COOKIE_MINUTES = 525600;

    /** @return 'net'|'gross' */
    public static function mode(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof User && $request->hasSession()) {
            if ($user->isStaff()) {
                return 'net';
            }

            $company = ActingCompany::current($request->session(), $user);
            if ($company !== null) {
                return $company->price_display_mode === 'gross' ? 'gross' : 'net';
            }
        }

        return $request->cookie(self::COOKIE) === 'net' ? 'net' : 'gross';
    }

    /**
     * Checkout and the order confirmation: the switch does not apply. A
     * consumer is always shown the VAT-inclusive total they pay (05.15 §5.4,
     * CCR Sch. 2); a trade buyer still sees their company's setting.
     *
     * @return 'net'|'gross'
     */
    public static function checkoutMode(Request $request): string
    {
        return self::canSwitch($request) ? 'gross' : self::mode($request);
    }

    /** Guests and public customers may switch; trade users and staff may not. */
    public static function canSwitch(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession()) {
            return true;
        }

        return ! $user->isStaff() && ActingCompany::current($request->session(), $user) === null;
    }

    /** @return array{mode: 'net'|'gross', can_switch: bool} */
    public static function shared(Request $request): array
    {
        return ['mode' => self::mode($request), 'can_switch' => self::canSwitch($request)];
    }
}
