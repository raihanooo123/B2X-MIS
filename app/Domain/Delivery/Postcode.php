<?php

namespace App\Domain\Delivery;

/**
 * A UK-style postcode's outward code, split the way 05.6 §4.1 needs it:
 * the **area** (1–2 letters) and the **district number** (1–2 digits; a
 * trailing district letter, as in `EC1A`, is not part of the number).
 *
 * `PO31 7AA` → area `PO`, district 31. `PO3 5AB` → area `PO`, district 3.
 * Matching is on area + number, never on string prefixes, so `PO3`
 * cannot be mistaken for a prefix of `PO30`–`PO41` (05.6 §4.1).
 */
final readonly class Postcode
{
    private function __construct(
        public string $area,
        public int $district,
    ) {}

    /** Null when the text has no recognisable outward code. */
    public static function parse(string $postcode): ?self
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');

        // The inward code is always digit + two letters; strip it if present.
        if (preg_match('/^(.+)(\d[A-Z]{2})$/', $compact, $m) === 1 && strlen($m[1]) >= 2) {
            $compact = $m[1];
        }

        if (preg_match('/^([A-Z]{1,2})(\d{1,2})[A-Z]?$/', $compact, $m) !== 1) {
            return null;
        }

        return new self($m[1], (int) $m[2]);
    }
}
