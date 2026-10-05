<?php

namespace App\Domain\Collection;

use App\Domain\Delivery\CarriageCalculator;
use App\Domain\Delivery\DeliveryQuote;
use App\Domain\Pricing\TaxRateResolver;
use App\Models\DeliveryZone;

final class CollectionCharge
{
    public function __construct(
        private readonly CollectionSettings $settings = new CollectionSettings,
        private readonly CarriageCalculator $calculator = new CarriageCalculator,
        private readonly TaxRateResolver $taxRates = new TaxRateResolver,
    ) {}

    public function quote(int $locationId, ?int $companyId, int $subtotalNetMinor): DeliveryQuote
    {
        $threshold = $this->settings->integer('collection.free_threshold_net_minor', 20000, $locationId);
        $zone = DeliveryZone::query()->where('code', 'GB_MAINLAND')->where('status', 'active')->first();
        $shortfall = max(0, $threshold - $subtotalNetMinor);
        if ($subtotalNetMinor >= $threshold) {
            return new DeliveryQuote('free', $zone, true, 'collection', null, 0, null, null, $threshold, 0);
        }
        if ($zone === null) {
            return new DeliveryQuote('manual_quote', null, false, 'collection', null, 0, null, null, $threshold, $shortfall, 'no_zone');
        }
        $rate = $this->calculator->rate($zone->id, 'collection', 0);
        if ($rate === null) {
            return new DeliveryQuote('manual_quote', $zone, true, 'collection', null, 0, null, null, $threshold, $shortfall, 'no_rate_band');
        }
        $taxRate = $this->taxRates->resolveForClass($rate->taxClassId, $companyId, 'GB');

        return new DeliveryQuote('rated', $zone, true, 'collection', 0, $rate->netMinor(), $taxRate, $rate->rateId, $threshold, $shortfall);
    }
}
