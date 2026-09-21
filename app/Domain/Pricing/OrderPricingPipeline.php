<?php

namespace App\Domain\Pricing;

use App\Models\OrderSpendBreak;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Doc 03 §7A.4 end to end — the three-phase sequence every checkout /
 * quote-conversion / RMA-refund call site should call rather than
 * composing PriceResolver/OrderLinePricer/SpendBreakResolver/
 * SpendBreakApportioner by hand:
 *
 *   Pass 1 (per line, item-level)   — PriceResolver + OrderLinePricer
 *   Pass 2 (order-level spend break) — SpendBreakResolver + SpendBreakApportioner
 *   Pass 3 (tax and totals)          — this class, directly
 *
 * Tax computed LAST, on the post-spend-break line value — §7A.4's whole
 * point, and the reason §6.3's older single-pass sequence is superseded.
 *
 * §7A.2's qualifying-subtotal exclusion of contract lines creates a
 * real sequencing wrinkle this class resolves explicitly rather than
 * ignoring: the subtotal used to *select* a break must exclude contract
 * lines unless that break's own `applies_to_contract_lines` says
 * otherwise — but which break wins isn't known until a subtotal is
 * chosen. This resolves it in (at most) two resolver calls: select
 * using the contract-excluding subtotal first (the conservative, more
 * common default — `applies_to_contract_lines` defaults to false, 02
 * §6.7); if the winning break allows contract lines, re-select using the
 * contract-including subtotal, which can only be equal or larger, so it
 * can only match an equal-or-better break, never lose the first one.
 *
 * Tax (doc 03 §10) is resolved in Pass 1 too, alongside price — it does
 * not depend on Pass 2's spend break (the break changes a line's NET
 * value, tax is computed on whatever net value survives to Pass 3,
 * whichever rate Pass 1 already found for that SKU/country/time).
 */
final class OrderPricingPipeline
{
    public function __construct(
        private readonly PriceResolver $priceResolver = new PriceResolver,
        private readonly OrderLinePricer $linePricer = new OrderLinePricer,
        private readonly SpendBreakResolver $spendBreakResolver = new SpendBreakResolver,
        private readonly SpendBreakApportioner $spendBreakApportioner = new SpendBreakApportioner,
        private readonly TaxRateResolver $taxRateResolver = new TaxRateResolver,
    ) {}

    /**
     * @param  list<OrderLineRequest>  $lines
     *
     * @throws InvalidArgumentException if $lines is empty
     */
    public function price(
        array $lines,
        ?int $companyId,
        ?int $tierId,
        string $currency = 'GBP',
        ?CarbonImmutable $at = null,
        int $shippingNetMinor = 0,
        string $deliveryCountryCode = 'GB',
    ): OrderPricingResult {
        if ($lines === []) {
            throw new InvalidArgumentException('An order must have at least one line.');
        }

        $at ??= CarbonImmutable::now();

        // PASS 1 — per line, item-level (§7A.4 steps 1-5)
        $pass1 = [];
        foreach ($lines as $index => $request) {
            $resolvedPrice = $this->priceResolver->resolve($request->skuId, $companyId, $request->baseQty, $at, $currency);
            $pricedLine = $this->linePricer->priceLine($resolvedPrice, $request->lineDiscountE4);
            $taxRateBp = $this->taxRateResolver->resolve($request->skuId, $companyId, $deliveryCountryCode, $at);

            $pass1[$index] = [
                'resolved' => $resolvedPrice,
                'priced' => $pricedLine,
                'taxRateBp' => $taxRateBp,
            ];
        }

        // PASS 2 — order-level spend break (§7A.4 steps 6-10)
        [$spendBreak, $qualifyingLines, $qualifyingSubtotal] = $this->selectSpendBreak($pass1, $tierId, $companyId, $currency, $at);

        $apportionment = $spendBreak !== null
            ? $this->spendBreakApportioner->apportion($spendBreak, $qualifyingSubtotal, $qualifyingLines)
            : null;
        $spendBreakDiscountMinor = $apportionment === null ? 0 : $apportionment->discountMinor;

        // PASS 3 — tax and totals (§7A.4 steps 11-15)
        $pricedLines = [];
        $subtotalNetMinor = 0;
        $taxMinor = 0;

        foreach ($pass1 as $index => $p) {
            $spendDiscountMinor = $apportionment?->perLine[$index] ?? 0;
            $lineNetMinor = $p['priced']->itemNetMinor - $spendDiscountMinor;
            $lineTaxMinor = Money::roundHalfUpDiv($lineNetMinor * $p['taxRateBp'], 10000);
            $lineGrossMinor = $lineNetMinor + $lineTaxMinor;

            $pricedLines[] = new PricedOrderLine(
                skuId: $p['resolved']->skuId,
                baseQty: $p['resolved']->baseQty,
                unitPriceNetE4: $p['resolved']->unitPriceE4,
                priceSource: $p['resolved']->priceSource,
                priceListId: $p['resolved']->priceListId,
                priceListItemId: $p['resolved']->priceListItemId,
                appliedBreakQty: $p['resolved']->appliedBreakQty,
                lineDiscountMinor: $p['priced']->lineDiscountMinor,
                lineSpendDiscountMinor: $spendDiscountMinor,
                lineNetMinor: $lineNetMinor,
                taxRateBp: $p['taxRateBp'],
                lineTaxMinor: $lineTaxMinor,
                lineGrossMinor: $lineGrossMinor,
                unitCostE4: $p['resolved']->unitCostE4,
                skuCostId: $p['resolved']->skuCostId,
            );

            $subtotalNetMinor += $lineNetMinor;
            $taxMinor += $lineTaxMinor;
        }

        return new OrderPricingResult(
            lines: $pricedLines,
            spendBreakId: $spendBreak?->id,
            spendBreakDiscountMinor: $spendBreakDiscountMinor,
            subtotalNetMinor: $subtotalNetMinor,
            taxMinor: $taxMinor,
            shippingNetMinor: $shippingNetMinor,
            totalGrossMinor: $subtotalNetMinor + $shippingNetMinor + $taxMinor,
        );
    }

    /**
     * @param  array<int, array{resolved: ResolvedPrice, priced: PricedLine, taxRateBp: int}>  $pass1
     * @return array{0: ?OrderSpendBreak, 1: array<int, int>, 2: int}
     */
    private function selectSpendBreak(array $pass1, ?int $tierId, ?int $companyId, string $currency, CarbonImmutable $at): array
    {
        $excludingContract = [];
        $all = [];
        foreach ($pass1 as $index => $p) {
            $all[$index] = $p['priced']->itemNetMinor;
            if ($p['resolved']->priceSource !== PriceSource::Contract) {
                $excludingContract[$index] = $p['priced']->itemNetMinor;
            }
        }

        $subtotalExcludingContract = array_sum($excludingContract);
        $subtotalIncludingContract = array_sum($all);

        $excludingResult = $subtotalExcludingContract > 0
            ? $this->spendBreakResolver->resolve($subtotalExcludingContract, $tierId, $companyId, $currency, $at)
            : null;

        // No contract line in this order at all — the two subtotals are
        // identical, so there is nothing a second query could find that
        // the first didn't already see.
        if ($subtotalIncludingContract === $subtotalExcludingContract) {
            return [$excludingResult, $excludingContract, $subtotalExcludingContract];
        }

        // A contract line exists. Query again against the larger,
        // contract-inclusive subtotal — but only a break that itself
        // declares `applies_to_contract_lines` is entitled to have been
        // found using it; a break that doesn't must stand or fall on the
        // contract-excluding subtotal, exactly as $excludingResult did.
        $includingResult = $this->spendBreakResolver->resolve($subtotalIncludingContract, $tierId, $companyId, $currency, $at);

        if ($includingResult !== null && $includingResult->applies_to_contract_lines) {
            return [$includingResult, $all, $subtotalIncludingContract];
        }

        return [$excludingResult, $excludingContract, $subtotalExcludingContract];
    }
}
