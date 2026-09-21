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
 * construction once an address exists." That address now exists at both
 * call sites this resolves for — OrderPricingPipeline (checkout, via
 * resolve()) and BulkPriceResolver (order-pad, via resolveMany()) — so
 * this class is that separate resolver for both, not a change to
 * PriceResolver itself, which has no delivery address in its own
 * single-SKU signature and correctly stays at `taxRateBp = 0`.
 *
 * "Rate resolved from skus.tax_class_id → tax_rates filtered on
 * country_code (from the delivery address) and the order's timestamp.
 * Company-level tax_exempt zeroes the rate." (§10, verbatim.) Never
 * returns a guessed/default rate for an unmatched (tax_class, country,
 * time) — that is a data-setup gap, surfaced loudly via
 * NoTaxRateException, exactly like PriceResolver's own §4.6 failure
 * modes for prices.
 *
 * Doc 05.1 §11's exact-match requirement — order-pad totals must equal
 * checkout's re-resolution exactly — is why both call sites share this
 * one class rather than the bulk path keeping its old `taxRateBp = 0`
 * placeholder: two different tax numbers for the same basket is exactly
 * the drift that requirement exists to catch.
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

    /**
     * Doc 03 §8's bulk path — one query (or two, counting the tax-exempt
     * check) regardless of row count, matching Q-A/Q-B's own "exactly N
     * queries regardless of row count" contract; the sku_id → tax_class_id
     * map is passed in rather than re-queried, since BulkPriceResolver's
     * own active-SKU query already produces it at no extra cost.
     *
     * Unlike resolve(), a SKU whose tax class has no matching rate is
     * OMITTED from the returned map rather than throwing —
     * BulkPriceResolver buckets that SKU into its own per-row $failures
     * the same way it already does for NoBasePriceListException, so one
     * SKU's bad tax setup never fails the whole page. This is the batch
     * analogue of BulkPriceResolution's own documented contract: "a
     * 100-row order-pad page cannot let one unpriceable SKU fail the
     * whole page."
     *
     * @param  array<int, int>  $taxClassIdBySkuId  keyed by sku_id
     * @return array<int, int> rate_bp keyed by sku_id — only for SKUs that resolved
     */
    public function resolveMany(array $taxClassIdBySkuId, ?int $companyId, string $countryCode, CarbonImmutable $at): array
    {
        if ($taxClassIdBySkuId === []) {
            return [];
        }

        if ($companyId !== null && $this->isTaxExempt($companyId)) {
            return array_fill_keys(array_keys($taxClassIdBySkuId), 0);
        }

        $distinctClassIds = array_values(array_unique($taxClassIdBySkuId));

        $rateByClass = TaxRate::query()
            ->whereIn('tax_class_id', $distinctClassIds)
            ->where('country_code', $countryCode)
            ->whereNull('region')
            ->whereRaw('validity @> ?::timestamptz', [$this->timestampForQuery($at)])
            ->pluck('rate_bp', 'tax_class_id');

        $result = [];
        foreach ($taxClassIdBySkuId as $skuId => $taxClassId) {
            if ($rateByClass->has($taxClassId)) {
                $result[$skuId] = (int) $rateByClass[$taxClassId];
            }
        }

        return $result;
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
