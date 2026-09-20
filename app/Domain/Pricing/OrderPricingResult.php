<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §7A.4 Pass 3's order-level totals (§6.4), plus the winning
 * spend break's identity for `orders.spend_break_id` /
 * `orders.spend_break_discount_minor` (02 §8.2).
 */
final readonly class OrderPricingResult
{
    /**
     * @param  list<PricedOrderLine>  $lines
     */
    public function __construct(
        public array $lines,
        public ?int $spendBreakId,
        public int $spendBreakDiscountMinor,
        public int $subtotalNetMinor,
        public int $taxMinor,
        public int $shippingNetMinor,
        public int $totalGrossMinor,
    ) {}
}
