<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Times are stored in UTC (`app.timezone`, `timestamptz`) and shown to
 * people in one display timezone, `app.display_timezone` (Europe/London):
 * admin tables and infolists (defaults set in AppServiceProvider), the
 * custom text built around them, emails and the storefront. Anything that
 * prints a time uses this, never a hard-coded zone.
 *
 * Plain dates (`date` columns: incorporation, batch expiry) are not
 * instants and are never shifted.
 */
final class DisplayTime
{
    public const DATE_TIME = 'j M Y H:i';

    public const DATE = 'j M Y';

    public static function zone(): string
    {
        $zone = config('app.display_timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'Europe/London';
    }

    /** The same instant, in the display timezone. */
    public static function local(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::zone());
    }

    public static function format(?DateTimeInterface $at, string $format = self::DATE_TIME): string
    {
        return $at === null ? '—' : self::local($at)->format($format);
    }
}
