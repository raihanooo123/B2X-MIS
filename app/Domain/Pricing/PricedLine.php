<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §7A.4 Pass 1 output (item-level pricing, before any order-wide
 * spend break). `itemNetMinor` is deliberately NOT `order_lines.line_net_minor`
 * — that column (02 §8.3) holds the value AFTER pass 2 subtracts
 * `line_spend_discount_minor` (§7A.4 step 10). An order with no
 * qualifying spend break will simply persist `itemNetMinor` as
 * `line_net_minor` unchanged, but this class has no way to know whether
 * that will happen — that decision belongs to whatever runs pass 2
 * (SpendBreakApportioner, not built yet), never to this one.
 */
final readonly class PricedLine
{
    public function __construct(
        public int $skuId,
        public int $baseQty,
        public int $unitPriceNetE4,
        public int $grossLineE4,
        public int $lineDiscountE4,
        public int $itemNetMinor,
        public int $lineDiscountMinor,
    ) {}
}
