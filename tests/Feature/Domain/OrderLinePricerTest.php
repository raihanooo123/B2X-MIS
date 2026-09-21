<?php

use App\Domain\Pricing\OrderLinePricer;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\ResolvedPrice;

function makeResolvedPrice(int $unitPriceE4, int $baseQty): ResolvedPrice
{
    return new ResolvedPrice(
        skuId: 1,
        baseQty: $baseQty,
        unitPriceE4: $unitPriceE4,
        priceSource: PriceSource::Base,
        priceListId: 1,
        priceListItemId: 1,
        appliedBreakQty: 1,
        taxRateBp: 0,
        nextBreakQty: null,
        nextBreakUnitPriceE4: null,
        unitCostE4: null,
        skuCostId: null,
        promotionCapped: false,
    );
}

it('prices a line with no discount', function () {
    $resolved = makeResolvedPrice(unitPriceE4: 9800, baseQty: 10);

    $line = (new OrderLinePricer)->priceLine($resolved);

    expect($line->grossLineE4)->toBe(98000)
        ->and($line->lineDiscountE4)->toBe(0)
        ->and($line->lineDiscountMinor)->toBe(0)
        ->and($line->itemNetMinor)->toBe(980); // 98000 e4 / 100 = 980 pence exactly
});

it('reproduces the §3.3 worked example exactly: 1,440 units at £0.9212', function () {
    $resolved = makeResolvedPrice(unitPriceE4: 9212, baseQty: 1440);

    $line = (new OrderLinePricer)->priceLine($resolved);

    expect($line->itemNetMinor)->toBe(132653); // £1,326.53
});

it('applies a line discount before the single e4-to-minor conversion', function () {
    // gross = 100000 e4, a 600 e4 discount -> net 99400 e4 -> 994 minor
    $resolved = makeResolvedPrice(unitPriceE4: 10000, baseQty: 10);

    $line = (new OrderLinePricer)->priceLine($resolved, lineDiscountE4: 600);

    expect($line->grossLineE4)->toBe(100000)
        ->and($line->lineDiscountE4)->toBe(600)
        ->and($line->itemNetMinor)->toBe(994)
        ->and($line->lineDiscountMinor)->toBe(6);
});

it('rejects a negative line discount', function () {
    $resolved = makeResolvedPrice(unitPriceE4: 9800, baseQty: 1);

    expect(fn () => (new OrderLinePricer)->priceLine($resolved, lineDiscountE4: -1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a discount that would produce a negative line', function () {
    $resolved = makeResolvedPrice(unitPriceE4: 9800, baseQty: 1);

    expect(fn () => (new OrderLinePricer)->priceLine($resolved, lineDiscountE4: 9801))
        ->toThrow(InvalidArgumentException::class);
});

it('allows a discount exactly equal to the gross line value, netting to zero', function () {
    $resolved = makeResolvedPrice(unitPriceE4: 9800, baseQty: 1);

    $line = (new OrderLinePricer)->priceLine($resolved, lineDiscountE4: 9800);

    expect($line->itemNetMinor)->toBe(0);
});

it('carries the resolved sku and quantity through unchanged', function () {
    $resolved = new ResolvedPrice(
        skuId: 42,
        baseQty: 7,
        unitPriceE4: 5000,
        priceSource: PriceSource::Tier,
        priceListId: 3,
        priceListItemId: 9,
        appliedBreakQty: 1,
        taxRateBp: 0,
        nextBreakQty: null,
        nextBreakUnitPriceE4: null,
        unitCostE4: null,
        skuCostId: null,
        promotionCapped: false,
    );

    $line = (new OrderLinePricer)->priceLine($resolved);

    expect($line->skuId)->toBe(42)
        ->and($line->baseQty)->toBe(7)
        ->and($line->unitPriceNetE4)->toBe(5000);
});
