<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Doc 03 §7A.4 Pass 1 — "per line, item-level" — steps 1-5 exactly.
 * §7A.4 explicitly supersedes §6.3's single-pass version: tax is no
 * longer computed here, only after the order-wide spend break is known
 * (Pass 3, a separate not-yet-built class). This class's only output is
 * PricedLine::$itemNetMinor, the ONE e4→minor conversion for the line
 * (CLAUDE.md invariant 1).
 *
 * `lineDiscountE4` (03 §7: coupon or manual override) is a caller input,
 * not resolved here — resolving *which* discount applies and its amount
 * is a different concern (coupon redemption / manual-override entry),
 * not line pricing.
 *
 * `line_discount_minor` (02 §8.3's actual persisted column, snapshotted
 * for the price audit log and reporting) is derived by its own
 * independent roundHalfUpDiv call on lineDiscountE4 — this is a second
 * conversion, but of a different quantity than the line's net value, so
 * it does not double-round the same figure. It never feeds back into
 * itemNetMinor's calculation, which is computed directly from
 * (grossLineE4 - lineDiscountE4) in e4 space first.
 */
final class OrderLinePricer
{
    /**
     * @throws InvalidArgumentException if lineDiscountE4 is negative or exceeds the line's gross value
     */
    public function priceLine(ResolvedPrice $resolvedPrice, int $lineDiscountE4 = 0): PricedLine
    {
        if ($lineDiscountE4 < 0) {
            throw new InvalidArgumentException("lineDiscountE4 must not be negative, got {$lineDiscountE4}.");
        }

        $grossLineE4 = $resolvedPrice->unitPriceE4 * $resolvedPrice->baseQty;

        if ($lineDiscountE4 > $grossLineE4) {
            // 03 §7: "No discount may produce a negative line." Rejected
            // here rather than silently clamped, matching how every
            // other invalid-state path in this domain (PriceResolver's
            // §4.6 failures) is handled — loud, not silent.
            throw new InvalidArgumentException(
                "lineDiscountE4 ({$lineDiscountE4}) exceeds the line's gross value ({$grossLineE4}) — would produce a negative line."
            );
        }

        $itemNetLineE4 = $grossLineE4 - $lineDiscountE4;

        return new PricedLine(
            skuId: $resolvedPrice->skuId,
            baseQty: $resolvedPrice->baseQty,
            unitPriceNetE4: $resolvedPrice->unitPriceE4,
            grossLineE4: $grossLineE4,
            lineDiscountE4: $lineDiscountE4,
            itemNetMinor: Money::roundHalfUpDiv($itemNetLineE4, 100),
            lineDiscountMinor: Money::roundHalfUpDiv($lineDiscountE4, 100),
        );
    }
}
