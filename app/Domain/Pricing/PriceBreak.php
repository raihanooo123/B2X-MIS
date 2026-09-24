<?php

namespace App\Domain\Pricing;

/**
 * One rung of a SKU's **effective** break ladder — doc 06 §9.1's "full
 * break table," returned alongside the resolved price so the order pad
 * can recompute a quantity change client-side with no round trip (05.1
 * §5.1).
 *
 * Effective, not one price list's rows: from `minBaseQty` upwards (until
 * the next rung) resolution yields exactly `unitPriceE4` from a list of
 * `priceSource`. That is what lets the client reproduce the server
 * exactly when a different list wins at a higher quantity — a customer
 * list holding only a 1,000+ row (03 §4.3's fall-through), or the §4.5
 * promotion cap. `priceSource` is carried per rung because 03 §7A.2's
 * contract-line exclusion depends on it.
 */
final readonly class PriceBreak
{
    public function __construct(
        public int $minBaseQty,
        public int $unitPriceE4,
        public PriceSource $priceSource,
    ) {}
}
