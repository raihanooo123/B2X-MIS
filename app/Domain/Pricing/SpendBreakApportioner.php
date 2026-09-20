<?php

namespace App\Domain\Pricing;

use App\Models\OrderSpendBreak;
use InvalidArgumentException;

/**
 * Doc 03 §7A.3 exactly. A pure calculation — no queries, no side
 * effects, callers own persistence. `Σ perLine = discountMinor` is a
 * hard invariant this class must never violate: it is what keeps a
 * VAT-per-line invoice reconciling with the order total (§7A.3's own
 * justification for apportioning at all).
 */
final class SpendBreakApportioner
{
    /**
     * @param  array<int|string, int>  $qualifyingLineNetMinors  line_item_net_minor for qualifying lines only, keyed by whatever identifies a line to the caller
     *
     * @throws InvalidArgumentException if $qualifyingSubtotalMinor does not match the sum of $qualifyingLineNetMinors, or is not positive
     */
    public function apportion(OrderSpendBreak $break, int $qualifyingSubtotalMinor, array $qualifyingLineNetMinors): SpendBreakApportionment
    {
        if ($qualifyingSubtotalMinor <= 0) {
            throw new InvalidArgumentException("qualifyingSubtotalMinor must be positive, got {$qualifyingSubtotalMinor}.");
        }

        if ($qualifyingLineNetMinors === []) {
            throw new InvalidArgumentException('qualifyingLineNetMinors must not be empty when qualifyingSubtotalMinor is positive.');
        }

        if (array_sum($qualifyingLineNetMinors) !== $qualifyingSubtotalMinor) {
            throw new InvalidArgumentException('qualifyingSubtotalMinor must equal the sum of qualifyingLineNetMinors.');
        }

        $discountMinor = $this->discountMinor($break, $qualifyingSubtotalMinor);

        if ($discountMinor <= 0) {
            return new SpendBreakApportionment(0, array_map(static fn () => 0, $qualifyingLineNetMinors));
        }

        $perLine = [];
        $allocated = 0;
        foreach ($qualifyingLineNetMinors as $key => $lineNetMinor) {
            $share = Money::roundHalfUpDiv($discountMinor * $lineNetMinor, $qualifyingSubtotalMinor);
            $perLine[$key] = $share;
            $allocated += $share;
        }

        // §7A.3: "remainder = discount_minor − Σ line_spend_discount_minor;
        // assign remainder to the line with the largest line_item_net_minor."
        // Ties broken by lowest key, undocumented but needed for a
        // deterministic result independent of the caller's array order.
        $remainder = $discountMinor - $allocated;
        if ($remainder !== 0) {
            $largestKey = $this->keyOfLargest($qualifyingLineNetMinors);
            $perLine[$largestKey] += $remainder;
        }

        return new SpendBreakApportionment($discountMinor, $perLine);
    }

    private function discountMinor(OrderSpendBreak $break, int $qualifyingSubtotalMinor): int
    {
        if ($break->discount_type === 'percentage') {
            if ($break->discount_rate_bp === null) {
                // order_spend_breaks_discount_chk (02 §6.7) guarantees this
                // never happens for a real row; guarded so a violated
                // invariant fails loudly here rather than silently pricing
                // a 0% discount.
                throw new InvalidArgumentException("OrderSpendBreak {$break->id} has discount_type='percentage' but a null discount_rate_bp.");
            }

            $percentageAmount = Money::roundHalfUpDiv($qualifyingSubtotalMinor * $break->discount_rate_bp, 10000);

            return $break->max_discount_minor === null
                ? $percentageAmount
                : min($percentageAmount, $break->max_discount_minor);
        }

        if ($break->discount_amount_minor === null) {
            throw new InvalidArgumentException("OrderSpendBreak {$break->id} has discount_type='fixed' but a null discount_amount_minor.");
        }

        return min($break->discount_amount_minor, $qualifyingSubtotalMinor);
    }

    /**
     * @param  array<int|string, int>  $lineNetMinors
     * @return int|string
     */
    private function keyOfLargest(array $lineNetMinors)
    {
        $bestKey = array_key_first($lineNetMinors);

        if ($bestKey === null) {
            // Unreachable in practice — apportion() already rejects an
            // empty $qualifyingLineNetMinors before this is ever called —
            // but this method has no way to see that guarantee on its own.
            throw new InvalidArgumentException('lineNetMinors must not be empty.');
        }

        $bestValue = $lineNetMinors[$bestKey];

        foreach ($lineNetMinors as $key => $value) {
            if ($value > $bestValue) {
                $bestKey = $key;
                $bestValue = $value;
            }
        }

        return $bestKey;
    }
}
