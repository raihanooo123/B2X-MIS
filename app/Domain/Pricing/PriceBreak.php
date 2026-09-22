<?php

namespace App\Domain\Pricing;

/**
 * One rung of a price list's break ladder for a single SKU — doc 06
 * §9.1's "full break table," returned alongside the resolved price so
 * the order pad can recompute a quantity change client-side with no
 * round trip (05.1 §5.1).
 */
final readonly class PriceBreak
{
    public function __construct(
        public int $minBaseQty,
        public int $unitPriceE4,
    ) {}
}
