<?php

namespace App\Filament\Support;

/**
 * CLAUDE.md invariant 1: money is integer only, everywhere, including
 * display. Every method here works exclusively in integer division and
 * string concatenation — no float appears at any point, not even inside
 * `number_format()`, which internally casts through a double and is
 * avoided here for that reason.
 */
final class MoneyFormatter
{
    /**
     * `_minor` (whole pence) → "£12.34".
     */
    public static function minor(?int $amountMinor): ?string
    {
        if ($amountMinor === null) {
            return null;
        }

        $negative = $amountMinor < 0;
        $absolute = abs($amountMinor);

        $pounds = intdiv($absolute, 100);
        $pence = $absolute % 100;

        return ($negative ? '-' : '').'£'.self::groupThousands($pounds).'.'.str_pad((string) $pence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * `_e4` (ten-thousandths of a pound) → "£12.3456", the full 4dp
     * precision — this scale exists specifically to carry sub-penny
     * unit prices (03 §3.3), so display rounds nothing away.
     */
    public static function e4(?int $amountE4): ?string
    {
        if ($amountE4 === null) {
            return null;
        }

        $negative = $amountE4 < 0;
        $absolute = abs($amountE4);

        $pounds = intdiv($absolute, 10000);
        $fraction = $absolute % 10000;

        return ($negative ? '-' : '').'£'.self::groupThousands($pounds).'.'.str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);
    }

    /**
     * For an editable money field: `_minor` integer → plain "12.34"
     * (no currency symbol, no thousands separator — a value a form
     * input can round-trip). Pure integer arithmetic.
     */
    public static function minorToDecimalString(?int $amountMinor): ?string
    {
        if ($amountMinor === null) {
            return null;
        }

        $negative = $amountMinor < 0;
        $absolute = abs($amountMinor);

        $pounds = intdiv($absolute, 100);
        $pence = $absolute % 100;

        return ($negative ? '-' : '').$pounds.'.'.str_pad((string) $pence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The inverse of minorToDecimalString(): a submitted "12.34" string
     * → `_minor` integer. Parsed with string splitting, never
     * `(int) round($x * 100)` — that path is a float multiplication in
     * a money conversion, exactly what CLAUDE.md invariant 1 forbids.
     * A third decimal digit is truncated, not rounded, since a form
     * input constrained to 2dp by its own validation rule should never
     * produce one; this is a defensive floor, not a rounding policy.
     */
    public static function decimalStringToMinor(?string $decimal): ?int
    {
        if ($decimal === null || trim($decimal) === '') {
            return null;
        }

        $decimal = trim($decimal);
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');

        [$pounds, $pence] = array_pad(explode('.', $decimal, 2), 2, '0');
        $pounds = $pounds === '' ? '0' : $pounds;
        $pence = str_pad(substr($pence, 0, 2), 2, '0', STR_PAD_RIGHT);

        $minor = ((int) $pounds) * 100 + (int) $pence;

        return $negative ? -$minor : $minor;
    }

    private static function groupThousands(int $wholePounds): string
    {
        $digits = (string) $wholePounds;
        $length = strlen($digits);
        $grouped = '';

        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $grouped .= ',';
            }
            $grouped .= $digits[$i];
        }

        return $grouped;
    }
}
