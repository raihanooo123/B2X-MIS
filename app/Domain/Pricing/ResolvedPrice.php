<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §4.5. The engine never returns null and never substitutes a
 * default (§4.6) — every successful call to PriceResolver::resolve()
 * returns exactly one of these.
 *
 * `unitCostE4` is resolved unconditionally here; redacting it outside
 * admin/rep contexts is a policy-gate concern for the API/serialization
 * layer, not this resolver (CLAUDE.md invariant 9) — nulling it out
 * "just in case" here would duplicate that gate instead of trusting it.
 *
 * `taxRateBp` is currently always 0. Doc 03 §10 resolves it from
 * `skus.tax_class_id` against `tax_rates.country_code`, but that country
 * comes from the delivery address — an input `resolve()`'s own §4.1
 * signature does not carry (it only has skuId/companyId/baseQty/at/
 * currency). Wiring this up needs either a signature change or a
 * separate TaxRateResolver called from order/line construction once an
 * address exists; deliberately not guessed here. See PriceResolver's
 * class docblock.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public int $skuId,
        public int $baseQty,
        public int $unitPriceE4,
        public PriceSource $priceSource,
        public ?int $priceListId,
        public ?int $priceListItemId,
        public int $appliedBreakQty,
        public int $taxRateBp,
        public ?int $nextBreakQty,
        public ?int $nextBreakUnitPriceE4,
        public ?int $unitCostE4,
        public bool $promotionCapped,
    ) {}
}
