<?php

namespace App\Domain\Notifications\Notices;

use App\Filament\Support\MoneyFormatter;
use App\Support\DisplayTime;
use DateTimeInterface;

/**
 * Formatting for notification content: money through the integer-only
 * formatter (invariant 1), dates in the display timezone (05.12 §13,
 * App\Support\DisplayTime).
 */
trait FormatsForMail
{
    protected static function money(int $amountMinor): string
    {
        return (string) MoneyFormatter::minor($amountMinor);
    }

    protected static function date(?DateTimeInterface $at): string
    {
        return DisplayTime::format($at, 'j F Y');
    }
}
