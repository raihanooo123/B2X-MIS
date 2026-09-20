<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §4.4: "Candidate promotion list ids are resolved separately and
 * cached, because promotion eligibility involves category and brand
 * rules that do not belong in this query." PriceResolver depends on this
 * interface rather than querying `promotions`/`promotion_rules` itself.
 */
interface PromotionEligibilityResolver
{
    /**
     * @return list<int> price_lists.id values this company may see promotion pricing from
     */
    public function eligiblePriceListIds(?int $companyId): array;
}
