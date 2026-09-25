<?php

namespace App\Domain\Delivery;

use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Domain\Pricing\TaxRateResolver;
use App\Models\DeliveryZone;
use Carbon\CarbonImmutable;

/**
 * 05.6 §4–6 end to end: the carriage for these lines to this address,
 * given the order's post-spend-break net subtotal.
 *
 *   1. zone from the postcode (ZoneResolver) — unknown postcodes fall back
 *      to the mainland, flagged, never blocked;
 *   2. an unserviceable zone refuses; a manual-quote zone quotes by hand;
 *   3. at or above the carriage-paid threshold (ThresholdEvaluator) the
 *      carriage is free — there is nothing to rate or quote;
 *   4. otherwise weigh (ConsignmentWeigher) — missing weight data means a
 *      manual quote — and rate (CarriageCalculator) — no band means a
 *      manual quote;
 *   5. VAT on carriage at its own tax class's rate for the delivery
 *      country (05.6 §5.2) — never assumed to be the goods' rate.
 *
 * Checkout preview and POST /checkout both ask this, with the same inputs,
 * so the carriage a buyer is shown is the carriage they are charged.
 */
final class DeliveryQuoter
{
    public function __construct(
        private readonly ZoneResolver $zones = new ZoneResolver,
        private readonly ConsignmentWeigher $weigher = new ConsignmentWeigher,
        private readonly CarriageCalculator $calculator = new CarriageCalculator,
        private readonly ThresholdEvaluator $thresholds = new ThresholdEvaluator,
        private readonly TaxRateResolver $taxRates = new TaxRateResolver,
    ) {}

    /**
     * @param  list<ConsignmentLine>  $lines
     */
    public function quote(array $lines, DeliveryDestination $destination, ?int $companyId, int $subtotalNetMinor, ?CarbonImmutable $at = null): DeliveryQuote
    {
        $at ??= CarbonImmutable::now();
        $resolution = $this->zones->resolve($destination->postcode, $destination->countryCode);
        $zone = $resolution->zone;

        $threshold = $this->thresholds->carriagePaidThresholdNetMinor($zone, $companyId);
        $shortfall = max(0, $threshold - $subtotalNetMinor);
        $quote = fn (string $status, ?string $reason, ?string $method = null, ?int $weightG = null, int $net = 0, ?int $taxRateBp = null, ?int $rateId = null) => new DeliveryQuote(
            $status, $zone, $resolution->recognised, $method, $weightG, $net, $taxRateBp, $rateId, $threshold, $shortfall, $reason,
        );

        if ($zone === null) {
            return $quote('manual_quote', 'no_zone');
        }
        if (! $zone->is_serviceable) {
            return $quote('unserviceable', 'zone_unserviceable');
        }
        if ($zone->requires_manual_quote) {
            return $quote('manual_quote', 'zone_manual_quote');
        }

        $consignment = $this->weigher->weigh($lines, $companyId);

        if ($this->thresholds->isCarriagePaid($subtotalNetMinor, $zone, $companyId)) {
            return $quote('free', null, $consignment->method, $consignment->weightG);
        }

        if (! $consignment->isRateable() || $consignment->weightG === null) {
            return $quote('manual_quote', 'missing_weight', $consignment->method);
        }

        $carriage = $this->calculator->rate($zone->id, $consignment->method, $consignment->weightG, $at);
        if ($carriage === null) {
            return $quote('manual_quote', 'no_rate_band', $consignment->method, $consignment->weightG);
        }

        try {
            $taxRateBp = $this->taxRates->resolveForClass($carriage->taxClassId, $companyId, $destination->countryCode, $at);
        } catch (NoTaxRateException) {
            return $quote('manual_quote', 'no_carriage_tax_rate', $consignment->method, $consignment->weightG);
        }

        return $quote('rated', null, $consignment->method, $consignment->weightG, $carriage->netMinor(), $taxRateBp, $carriage->rateId);
    }

    /** The zone a destination falls in, for display without a full quote. */
    public function zoneFor(DeliveryDestination $destination): ?DeliveryZone
    {
        return $this->zones->resolve($destination->postcode, $destination->countryCode)->zone;
    }
}
