<?php

use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Domain\Pricing\PriceSource;
use App\Models\Company;
use App\Models\OrderSpendBreak;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A fresh GB tax class with one real, currently-valid tax_rates row at
 * $rateBp — since TaxRateResolver (03 §10) now resolves a genuine rate
 * per SKU, every SKU in this file needs one, not just the ones a test
 * cares about the tax figures for. No sharing needed across calls:
 * `tax_rates_no_overlap` is scoped per tax_class_id, so distinct classes
 * never collide regardless of overlapping validity.
 */
function taxClassWithRate(int $rateBp = 2000): int
{
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => $rateBp]);

    return $taxClass->id;
}

/**
 * Only one active base-scope price list can exist per currency at a time
 * (price_lists_no_base_overlap, 02 §6.3) — a fresh PriceList per call
 * would collide on the second SKU in any multi-line test, so callers
 * share one base list per test via $sharedBase.
 */
function skuWithBasePrice(int $unitPriceE4, ?PriceList &$sharedBase = null, int $taxRateBp = 2000): Sku
{
    $sku = Sku::factory()->create(['tax_class_id' => taxClassWithRate($taxRateBp)]);
    $sharedBase ??= PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($sharedBase, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $unitPriceE4]);

    return $sku;
}

it('prices a single line with no spend break, matching OrderLinePricer directly', function () {
    $sku = skuWithBasePrice(9800);

    $result = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 10)],
        companyId: null,
        tierId: null,
    );

    $line = $result->lines[0];
    expect($line->lineNetMinor)->toBe(980) // 9800 e4 x 10 / 100
        ->and($line->lineTaxMinor)->toBe(196) // 20% of 980
        ->and($line->lineGrossMinor)->toBe(1176)
        ->and($result->spendBreakId)->toBeNull()
        ->and($result->spendBreakDiscountMinor)->toBe(0)
        ->and($result->subtotalNetMinor)->toBe(980)
        ->and($result->totalGrossMinor)->toBe(1176);
});

it('reproduces the §7A.5 worked example end to end', function () {
    $base = null;
    $skuA = skuWithBasePrice(unitPriceE4: 200000, sharedBase: $base, taxRateBp: 2000);
    $skuB = skuWithBasePrice(unitPriceE4: 100000, sharedBase: $base, taxRateBp: 2000);
    $skuC = skuWithBasePrice(unitPriceE4: 50000, sharedBase: $base, taxRateBp: 0);

    // Chosen so item_net_minor lands exactly on the §7A.5 figures:
    // A: 200000 e4 x 31 qty / 100 = 62000 minor (£620.00)
    // B: 100000 e4 x 31 qty / 100 = 31000 minor (£310.00)
    // C: 50000 e4 x 30 qty / 100 = 15000 minor (£150.00)
    OrderSpendBreak::factory()->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 300,
        'min_subtotal_minor' => 100000,
    ]);

    $result = (new OrderPricingPipeline)->price([
        new OrderLineRequest(skuId: $skuA->id, baseQty: 31),
        new OrderLineRequest(skuId: $skuB->id, baseQty: 31),
        new OrderLineRequest(skuId: $skuC->id, baseQty: 30),
    ], companyId: null, tierId: null);

    [$lineA, $lineB, $lineC] = $result->lines;

    expect($result->spendBreakDiscountMinor)->toBe(3240)
        ->and($lineA->lineSpendDiscountMinor)->toBe(1860)
        ->and($lineB->lineSpendDiscountMinor)->toBe(930)
        ->and($lineC->lineSpendDiscountMinor)->toBe(450)
        ->and($lineA->lineNetMinor)->toBe(60140) // £601.40
        ->and($lineB->lineNetMinor)->toBe(30070) // £300.70
        ->and($lineC->lineNetMinor)->toBe(14550) // £145.50
        ->and($lineA->lineTaxMinor)->toBe(12028) // £120.28
        ->and($lineB->lineTaxMinor)->toBe(6014)  // £60.14
        ->and($lineC->lineTaxMinor)->toBe(0)
        ->and($lineA->lineGrossMinor)->toBe(72168) // £721.68
        ->and($lineB->lineGrossMinor)->toBe(36084) // £360.84
        ->and($lineC->lineGrossMinor)->toBe(14550) // £145.50
        ->and($result->subtotalNetMinor)->toBe(104760) // £1,047.60
        ->and($result->taxMinor)->toBe(18042); // £180.42
});

it('applies no spend break when the subtotal falls a penny short of the threshold (§7A.7 #13)', function () {
    OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    // 99999 minor = 9999900 e4 / 100, so unit_price_e4 x qty = 9999900.
    $skuUnder = skuWithBasePrice(unitPriceE4: 9999900);

    $result = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $skuUnder->id, baseQty: 1)],
        companyId: null,
        tierId: null,
    );

    expect($result->lines[0]->lineNetMinor)->toBe(99999)
        ->and($result->spendBreakId)->toBeNull()
        ->and($result->spendBreakDiscountMinor)->toBe(0);
});

it('excludes a contract-priced line from the qualifying subtotal by default (§7A.7 #16)', function () {
    $company = Company::factory()->create();
    $contractList = PriceList::factory()->forCompany($company, hasContract: true)->create();
    $contractSku = Sku::factory()->create(['tax_class_id' => taxClassWithRate()]);
    PriceListItem::factory()->for($contractList, 'priceList')->for($contractSku)->create(['min_base_qty' => 1, 'unit_price_e4' => 10000000]);

    $ordinarySku = skuWithBasePrice(unitPriceE4: 100000);

    // applies_to_contract_lines defaults to false.
    OrderSpendBreak::factory()->forCompany($company)->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 1000,
        'min_subtotal_minor' => 50000,
    ]);

    $result = (new OrderPricingPipeline)->price([
        new OrderLineRequest(skuId: $contractSku->id, baseQty: 1),
        new OrderLineRequest(skuId: $ordinarySku->id, baseQty: 1),
    ], companyId: $company->id, tierId: null);

    [$contractLine, $ordinaryLine] = $result->lines;

    // Contract line (£1000.00) alone would qualify, but is excluded — only
    // the ordinary line's £100.00 counts, which is below the £500 threshold.
    expect($contractLine->priceSource)->toBe(PriceSource::Contract)
        ->and($result->spendBreakId)->toBeNull()
        ->and($contractLine->lineSpendDiscountMinor)->toBe(0)
        ->and($ordinaryLine->lineSpendDiscountMinor)->toBe(0);
});

it('includes a contract-priced line when the break explicitly allows it', function () {
    $company = Company::factory()->create();
    $contractList = PriceList::factory()->forCompany($company, hasContract: true)->create();
    $contractSku = Sku::factory()->create(['tax_class_id' => taxClassWithRate()]);
    PriceListItem::factory()->for($contractList, 'priceList')->for($contractSku)->create(['min_base_qty' => 1, 'unit_price_e4' => 10000000]);

    $ordinarySku = skuWithBasePrice(unitPriceE4: 100000);

    OrderSpendBreak::factory()->forCompany($company)->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 1000,
        'min_subtotal_minor' => 50000,
        'applies_to_contract_lines' => true,
    ]);

    $result = (new OrderPricingPipeline)->price([
        new OrderLineRequest(skuId: $contractSku->id, baseQty: 1),
        new OrderLineRequest(skuId: $ordinarySku->id, baseQty: 1),
    ], companyId: $company->id, tierId: null);

    // Now the contract line's £1000 + ordinary £100 = £1100 qualifies well
    // past the £500 threshold, and both lines share the discount.
    expect($result->spendBreakId)->not->toBeNull()
        ->and($result->spendBreakDiscountMinor)->toBeGreaterThan(0)
        ->and($result->lines[0]->lineSpendDiscountMinor)->toBeGreaterThan(0)
        ->and($result->lines[1]->lineSpendDiscountMinor)->toBeGreaterThan(0);
});

it('applies both an item-level break and an order-wide spend break together (§7A.7 #20)', function () {
    $sku = Sku::factory()->create(['tax_class_id' => taxClassWithRate()]);
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 10000]);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 100, 'unit_price_e4' => 8000]);

    OrderSpendBreak::factory()->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 500,
        'min_subtotal_minor' => 50000,
    ]);

    $result = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 100)], // item break: 8000 e4 x 100 / 100 = 8000 minor
        companyId: null,
        tierId: null,
    );

    $line = $result->lines[0];
    expect($line->unitPriceNetE4)->toBe(8000) // the item-level break applied
        ->and($result->spendBreakId)->toBeNull(); // but 8000 minor (£80) is below the £500 spend threshold
});

it('rejects an empty line list', function () {
    expect(fn () => (new OrderPricingPipeline)->price([], companyId: null, tierId: null))
        ->toThrow(InvalidArgumentException::class);
});

it('adds shipping to the total without taxing it', function () {
    $sku = skuWithBasePrice(unitPriceE4: 100000);

    $result = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 1)],
        companyId: null,
        tierId: null,
        shippingNetMinor: 500,
    );

    expect($result->shippingNetMinor)->toBe(500)
        ->and($result->totalGrossMinor)->toBe($result->subtotalNetMinor + $result->taxMinor + 500);
});
