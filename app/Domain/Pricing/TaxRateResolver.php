<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Models\Company;
use App\Models\Sku;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;

/**
 * Doc 03 §10 — tax rate resolution, the piece PriceResolver's own
 * docblock explicitly declined to build: "wiring this up needs either a
 * signature change or a separate TaxRateResolver called from order/line
 * construction once an address exists." That address now exists at the
 * call site this resolves for (OrderPricingPipeline, wired in from
 * CheckoutService's delivery country), so this class is that separate
 * resolver, not a change to PriceResolver itself — PriceResolver and
 * BulkPriceResolver (the order-pad / bulk-resolve display paths) still
 * have no delivery address in their signature and correctly stay at
 * `taxRateBp = 0`, unchanged by this class.
 *
 * "Rate resolved from skus.tax_class_id → tax_rates filtered on
 * country_code (from the delivery address) and the order's timestamp.
 * Company-level tax_exempt zeroes the rate." (§10, verbatim.) Never
 * returns a guessed/default rate for an unmatched (tax_class, country,
 * time) — that is a data-setup gap, surfaced loudly via
 * NoTaxRateException, exactly like PriceResolver's own §4.6 failure
 * modes for prices.
 *
 * `region` (tax_rates' third discriminator, alongside tax_class_id and
 * country_code) is deliberately ignored: nothing in this call chain
 * carries a sub-country region signal today (§10's own wording only
 * mentions "the delivery address country_code"), so this resolves
 * against the national rate (`region IS NULL`) only. A region-aware
 * resolution is additive later, not a behaviour change to this method.
 */
final class TaxRateResolver
{
    /**
     * @throws NoTaxRateException
     */
    public function resolve(int $skuId, ?int $companyId, string $countryCode, CarbonImmutable $at): int
    {
        if ($companyId !== null && $this->isTaxExempt($companyId)) {
            return 0;
        }

        $taxClassId = (int) Sku::query()->whereKey($skuId)->value('tax_class_id');

        $rateBp = TaxRate::query()
            ->where('tax_class_id', $taxClassId)
            ->where('country_code', $countryCode)
            ->whereNull('region')
            ->whereRaw('validity @> ?::timestamptz', [$this->timestampForQuery($at)])
            ->value('rate_bp');

        if ($rateBp === null) {
            throw new NoTaxRateException($taxClassId, $countryCode, $at);
        }

        return (int) $rateBp;
    }

    private function isTaxExempt(int $companyId): bool
    {
        return (bool) Company::query()->whereKey($companyId)->value('tax_exempt');
    }

    /**
     * Doc 02's `validity` columns default to `now()` at microsecond
     * precision; `Carbon::toIso8601String()` truncates to whole seconds,
     * which can put a row created and resolved against within the same
     * wall-clock second just outside its own lower bound. Same fix as
     * PriceResolver::timestampForQuery() — see that method's docblock
     * for the fuller explanation; duplicated here rather than shared
     * because the two resolvers have no common base class.
     */
    private function timestampForQuery(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s.uP');
    }
}
