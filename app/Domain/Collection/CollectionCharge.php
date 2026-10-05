<?php

namespace App\Domain\Collection;

use App\Domain\Delivery\CarriageCalculator;
use App\Domain\Delivery\DeliveryQuote;
use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Domain\Pricing\TaxRateResolver;
use App\Models\DeliveryZone;
use Carbon\CarbonImmutable;

/**
 * 05.6 §7A.2 step 3 — the charge for collecting: the `collection` method in
 * `delivery_rates`, free at or above `collection.free_threshold_net_minor`
 * (§6, post-discount net subtotal). There is no delivery country, so rule G
 * doesn't apply and the VAT rate is GB's. Rates are held on the mainland
 * zone, which is where the counter is. No rate, or no carriage tax rate,
 * blocks the order as any unrateable delivery does — never £0 by default.
 */
final class CollectionCharge
{
    public const ZONE_CODE = 'GB_MAINLAND';

    public function __construct(
        private readonly CollectionSettings $settings = new CollectionSettings,
        private readonly CarriageCalculator $calculator = new CarriageCalculator,
        private readonly TaxRateResolver $taxRates = new TaxRateResolver,
    ) {}

    public function quote(int $locationId, ?int $companyId, int $subtotalNetMinor): DeliveryQuote
    {
        $threshold = $this->settings->freeThresholdNetMinor($locationId);
        $shortfall = max(0, $threshold - $subtotalNetMinor);
        $zone = DeliveryZone::query()->where('code', self::ZONE_CODE)->where('status', 'active')->first();

        if ($subtotalNetMinor >= $threshold) {
            return new DeliveryQuote('free', $zone, true, 'collection', null, 0, null, null, $threshold, 0);
        }
        if ($zone === null) {
            return new DeliveryQuote('manual_quote', null, true, 'collection', null, 0, null, null, $threshold, $shortfall, 'no_zone');
        }
        $rate = $this->calculator->rate($zone->id, 'collection', 0);
        if ($rate === null) {
            return new DeliveryQuote('manual_quote', $zone, true, 'collection', null, 0, null, null, $threshold, $shortfall, 'no_rate_band');
        }
        try {
            $taxRateBp = $this->taxRates->resolveForClass($rate->taxClassId, $companyId, 'GB', CarbonImmutable::now());
        } catch (NoTaxRateException) {
            return new DeliveryQuote('manual_quote', $zone, true, 'collection', null, 0, null, $rate->rateId, $threshold, $shortfall, 'no_carriage_tax_rate');
        }

        return new DeliveryQuote('rated', $zone, true, 'collection', null, $rate->netMinor(), $taxRateBp, $rate->rateId, $threshold, $shortfall);
    }
}
