<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Doc 03 §6.1/§6.2. Money is integer only, everywhere in the pricing
 * path (CLAUDE.md invariant 1) — no float appears anywhere here, and
 * whether that stays true is a static-analysis rule's job (03 §6.2, not
 * this class's), not a runtime guard.
 *
 * `_e4` (ten-thousandths of a pound) and `_minor` (whole pence) are
 * different scales. This class does not know which scale a given int is
 * in — callers convert with roundHalfUpDiv($amountE4, 100), always at
 * the single documented boundary (03 §6.1), never anywhere else.
 */
final class Money
{
    /**
     * Half-up integer division: 0.5 rounds away from zero. Chosen over
     * banker's rounding because it matches UK accounting software and
     * customer expectations, and this runs once per line, not millions
     * of times where statistical bias would matter (03 §6.2).
     *
     * Assumes a non-negative numerator, as every quantity in this
     * domain is (line values and discounts are clamped >= 0 before
     * reaching here, 03 §7) — PHP's `%` returns a sign-following
     * remainder for negative operands, which this rounding rule does
     * not account for.
     */
    public static function roundHalfUpDiv(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException("denominator must be positive, got {$denominator}.");
        }

        $q = intdiv($numerator, $denominator);
        $r = $numerator % $denominator;

        return ($r * 2 >= $denominator) ? $q + 1 : $q;
    }
}
