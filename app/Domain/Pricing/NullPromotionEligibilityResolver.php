<?php

namespace App\Domain\Pricing;

/**
 * Default PromotionEligibilityResolver: no company is eligible for any
 * promotion. This is not a simplification — `promotions`/`promotion_rules`
 * (docs/02-domain-model-erd.md §14.8, drafted 2026-09-17) are not yet
 * signed off or migrated, so there is nothing a real implementation could
 * query. Rank 3 of §4.2 correctly never matches while this is in effect;
 * once §14 is signed off and built, bind a real implementation instead
 * of editing this class.
 */
final class NullPromotionEligibilityResolver implements PromotionEligibilityResolver
{
    public function eligiblePriceListIds(?int $companyId): array
    {
        return [];
    }
}
