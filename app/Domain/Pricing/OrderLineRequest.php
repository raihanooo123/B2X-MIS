<?php

namespace App\Domain\Pricing;

/**
 * One requested order line, as input to OrderPricingPipeline. Resolving
 * the price is the pipeline's job (via PriceResolver) — this only
 * carries what the caller must supply: what to price, how much, and any
 * line-level discount already decided (coupon/manual override, 03 §7).
 *
 * `taxRateBp` is caller-supplied rather than resolved by the pipeline:
 * doc 03 §10 (TaxRateResolver) is not built yet — see PriceResolver's
 * and ResolvedPrice's docblocks for why `resolve()` itself cannot derive
 * it (no delivery-address country code in scope). Defaults to 0, the
 * same placeholder used throughout this domain until that class exists.
 */
final readonly class OrderLineRequest
{
    public function __construct(
        public int $skuId,
        public int $baseQty,
        public int $lineDiscountE4 = 0,
        public int $taxRateBp = 0,
    ) {}
}
