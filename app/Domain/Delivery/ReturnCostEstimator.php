<?php

namespace App\Domain\Delivery;

use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\TaxRateResolver;
use Carbon\CarbonImmutable;

/**
 * 02 §27 (05.15 §9 A9) — what returning a pallet consignment would cost a
 * consumer who cancels for a change of mind. Goods that cannot be posted
 * must have that cost stated before ordering, or the trader bears it (CCR
 * Sch. 2 para (l), reg. 35(5)).
 *
 * Our own pallet rate for the consignment's zone and weight, from the same
 * `delivery_rates` lookup checkout uses (CarriageCalculator), ignoring the
 * carriage-paid threshold, plus VAT at carriage's own rate (05.6 §5.2), one
 * half-up rounding (03 §6.3). Gross: it is what the consumer would pay.
 *
 * Null when the consignment is not a pallet, or no rate or tax rate can be
 * found — then we collect the goods at our cost.
 */
final class ReturnCostEstimator
{
    public function __construct(
        private readonly CarriageCalculator $calculator = new CarriageCalculator,
        private readonly TaxRateResolver $taxRates = new TaxRateResolver,
    ) {}

    public function estimateGrossMinor(DeliveryQuote $quote, string $countryCode, ?CarbonImmutable $at = null): ?int
    {
        if ($quote->method !== 'pallet' || $quote->zone === null || $quote->weightG === null) {
            return null;
        }

        $at ??= CarbonImmutable::now();
        $rate = $this->calculator->rate($quote->zone->id, 'pallet', $quote->weightG, $at);
        if ($rate === null) {
            return null;
        }

        try {
            $taxRateBp = $this->taxRates->resolveForClass($rate->taxClassId, null, $countryCode, $at);
        } catch (NoTaxRateException) {
            return null;
        }

        $net = $rate->netMinor();

        return $net + Money::roundHalfUpDiv($net * $taxRateBp, 10000);
    }

    public static function isPallet(?DeliveryQuote $quote): bool
    {
        return $quote !== null && $quote->isChargeable() && $quote->method === 'pallet';
    }
}
