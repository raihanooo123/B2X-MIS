<?php

namespace App\Domain\Cms;

use App\Http\Support\PriceDisplay;
use LogicException;

/**
 * 05.11 §2.5 — every cookie the storefront sets or lets a third party set,
 * shown on the Cookies page. At launch all are strictly necessary (PECR
 * reg. 6(4)), so no consent banner is shown (05.15 §8.2).
 *
 * CookieRegistryTest walks the storefront and fails on any Set-Cookie name
 * missing here, so the page cannot fall behind what the site does. A
 * cookie of any other category needs a signed-off consent banner first
 * (05.11 §1): `entries()` refuses one in production.
 */
final class CookieRegistry
{
    public const STRICTLY_NECESSARY = 'strictly_necessary';

    /**
     * @return list<array{name: string, set_by: string, purpose: string, lifetime: string, category: string}>
     */
    public static function entries(): array
    {
        $entries = [
            [
                'name' => (string) config('session.cookie'),
                'set_by' => 'This website',
                'purpose' => 'Keeps you signed in and remembers your basket as you move between pages.',
                'lifetime' => 'Until you close your browser, or '.self::minutes((int) config('session.lifetime')).' of inactivity',
                'category' => self::STRICTLY_NECESSARY,
            ],
            [
                'name' => 'XSRF-TOKEN',
                'set_by' => 'This website',
                'purpose' => 'Protects the forms on this site from being submitted by another website (security).',
                'lifetime' => self::minutes((int) config('session.lifetime')),
                'category' => self::STRICTLY_NECESSARY,
            ],
            [
                'name' => PriceDisplay::COOKIE,
                'set_by' => 'This website',
                'purpose' => 'Remembers whether you chose to see prices including or excluding VAT.',
                'lifetime' => '1 year',
                'category' => self::STRICTLY_NECESSARY,
            ],
            [
                'name' => '__stripe_mid',
                'set_by' => 'Stripe (our card payment provider), on the payment step only',
                'purpose' => 'Fraud prevention for card payments.',
                'lifetime' => '1 year',
                'category' => self::STRICTLY_NECESSARY,
            ],
            [
                'name' => '__stripe_sid',
                'set_by' => 'Stripe (our card payment provider), on the payment step only',
                'purpose' => 'Fraud prevention for card payments.',
                'lifetime' => '30 minutes',
                'category' => self::STRICTLY_NECESSARY,
            ],
        ];

        self::assertConsentFree($entries);

        return $entries;
    }

    /**
     * No consent banner exists, so a cookie that needs consent cannot be
     * served in production (05.11 §1, 05.15 §8.2).
     *
     * @param  list<array{name: string, category: string}>  $entries
     */
    public static function assertConsentFree(array $entries): void
    {
        if (! app()->environment('production')) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry['category'] !== self::STRICTLY_NECESSARY) {
                throw new LogicException("Cookie {$entry['name']} is not strictly necessary: a consent banner must be specified and signed off first (05.11 §1, 05.15 §8.2).");
            }
        }
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::entries(), 'name');
    }

    private static function minutes(int $minutes): string
    {
        return $minutes % 60 === 0 && $minutes >= 60
            ? ($minutes / 60).' '.($minutes === 60 ? 'hour' : 'hours')
            : $minutes.' minutes';
    }
}
