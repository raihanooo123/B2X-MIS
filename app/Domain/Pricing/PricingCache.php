<?php

namespace App\Domain\Pricing;

use Illuminate\Support\Facades\Cache;

/**
 * 03 §9 cache keys, named in one place. Invalidation is event-driven: a
 * write that changes a company's candidate lists (a tier change, an
 * approval) forgets the key after its transaction commits. Nothing reads
 * `pricing:lists:{company_id}` yet — the resolvers query directly — so
 * today this is the hook the cache will rely on, not a live flush.
 */
final class PricingCache
{
    public static function companyListsKey(int $companyId): string
    {
        return "pricing:lists:{$companyId}";
    }

    public function forgetCompanyLists(int $companyId): void
    {
        Cache::forget(self::companyListsKey($companyId));
    }
}
