<?php

namespace App\Domain\Pricing;

/**
 * One requested order line, as input to OrderPricingPipeline. Resolving
 * the price is the pipeline's job (via PriceResolver) — this only
 * carries what the caller must supply: what to price, how much, and any
 * line-level discount already decided (coupon/manual override, 03 §7).
 *
 * No `taxRateBp` here: doc 03 §10 makes tax a genuinely *resolved* value
 * (tax_class_id + delivery country + order time, via TaxRateResolver),
 * never a client-supplied input — 06 §15 is explicit that a client
 * cannot set any price field on checkout, and a caller-supplied tax rate
 * would be exactly that. OrderPricingPipeline resolves it itself now.
 */
final readonly class OrderLineRequest
{
    public function __construct(
        public int $skuId,
        public int $baseQty,
        public int $lineDiscountE4 = 0,
    ) {}
}
