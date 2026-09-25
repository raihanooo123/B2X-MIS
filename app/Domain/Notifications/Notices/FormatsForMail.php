<?php

namespace App\Domain\Notifications\Notices;

use App\Filament\Support\MoneyFormatter;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Formatting for notification content: money through the integer-only
 * formatter (invariant 1), dates in UK local time (05.12 §13).
 */
trait FormatsForMail
{
    protected static function money(int $amountMinor): string
    {
        return (string) MoneyFormatter::minor($amountMinor);
    }

    protected static function date(?DateTimeInterface $at): string
    {
        return $at === null ? '—' : Carbon::instance($at)->timezone('Europe/London')->format('j F Y');
    }
}
