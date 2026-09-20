<?php

use App\Domain\Pricing\BulkPriceResolver;
use App\Domain\Pricing\Exceptions\NoBasePriceListException;
use App\Domain\Pricing\Exceptions\NotPurchasableException;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PromotionEligibilityResolver;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceTier;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bulkEligibleFor(int ...$priceListIds): PromotionEligibilityResolver
{
    return new class($priceListIds) implements PromotionEligibilityResolver
    {
        public function __construct(private readonly array $ids) {}

        public function eligiblePriceListIds(?int $companyId): array
        {
            return $this->ids;
        }
    };
}

it('resolves several SKUs in one call, matching a guest base price each', function () {
    $skuA = Sku::factory()->create();
    $skuB = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($skuA)->create(['min_base_qty' => 1, 'unit_price_e4' => 1000]);
    PriceListItem::factory()->for($base, 'priceList')->for($skuB)->create(['min_base_qty' => 1, 'unit_price_e4' => 2000]);

    $result = (new BulkPriceResolver)->resolveMany([$skuA->id, $skuB->id], null, 1);

    expect($result->resolved)->toHaveCount(2)
        ->and($result->failures)->toBe([])
        ->and($result->resolved[$skuA->id]->unitPriceE4)->toBe(1000)
        ->and($result->resolved[$skuB->id]->unitPriceE4)->toBe(2000);
});

it('reads the next break from the same batch, with no extra query', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 144, 'unit_price_e4' => 9200]);

    $result = (new BulkPriceResolver)->resolveMany([$sku->id], null, 50);

    expect($result->resolved[$sku->id]->unitPriceE4)->toBe(9800)
        ->and($result->resolved[$sku->id]->nextBreakQty)->toBe(144)
        ->and($result->resolved[$sku->id]->nextBreakUnitPriceE4)->toBe(9200);
});

it('reports a non-active SKU as a failure without affecting the rest of the batch', function () {
    $active = Sku::factory()->create();
    $discontinued = Sku::factory()->create(['status' => 'discontinued']);
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($active)->create(['min_base_qty' => 1, 'unit_price_e4' => 1000]);

    $result = (new BulkPriceResolver)->resolveMany([$active->id, $discontinued->id], null, 1);

    expect($result->resolved)->toHaveKey($active->id)
        ->and($result->failures[$discontinued->id])->toBe(NotPurchasableException::class)
        ->and($result->failures)->not->toHaveKey($active->id);
});

it('reports a SKU with no base price as a failure without affecting the rest of the batch', function () {
    $priced = Sku::factory()->create();
    $unpriced = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($priced)->create(['min_base_qty' => 1, 'unit_price_e4' => 1000]);

    $result = (new BulkPriceResolver)->resolveMany([$priced->id, $unpriced->id], null, 1);

    expect($result->resolved)->toHaveKey($priced->id)
        ->and($result->failures[$unpriced->id])->toBe(NoBasePriceListException::class);
});

it('returns an empty result for an empty SKU list', function () {
    $result = (new BulkPriceResolver)->resolveMany([], null, 1);

    expect($result->resolved)->toBe([])
        ->and($result->failures)->toBe([]);
});

it('throws for a non-positive base_qty', function () {
    $sku = Sku::factory()->create();

    expect(fn () => (new BulkPriceResolver)->resolveMany([$sku->id], null, 0))
        ->toThrow(InvalidArgumentException::class);
});

// -----------------------------------------------------------------
// Cross-consistency with PriceResolver — the two rankings (one in SQL,
// one in PHP) must never disagree. This is the test that would catch
// drift between them.
// -----------------------------------------------------------------

it('agrees with PriceResolver for a tier customer with a multi-break list', function () {
    $sku = Sku::factory()->create();
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    $tierList = PriceList::factory()->forTier($tier)->create();
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 144, 'unit_price_e4' => 9200]);

    $single = (new PriceResolver)->resolve($sku->id, $company->id, 200);
    $bulk = (new BulkPriceResolver)->resolveMany([$sku->id], $company->id, 200)->resolved[$sku->id];

    expect($bulk->unitPriceE4)->toBe($single->unitPriceE4)
        ->and($bulk->priceSource)->toBe($single->priceSource)
        ->and($bulk->appliedBreakQty)->toBe($single->appliedBreakQty)
        ->and($bulk->nextBreakQty)->toBe($single->nextBreakQty);
});

it('agrees with PriceResolver when a promotion is capped by tier pricing', function () {
    $sku = Sku::factory()->create();
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    $tierList = PriceList::factory()->forTier($tier)->create();
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 7000]);
    $promoList = PriceList::factory()->forPromotion(promotionId: 5)->create();
    PriceListItem::factory()->for($promoList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9000]);

    $eligibility = bulkEligibleFor($promoList->id);
    $single = (new PriceResolver($eligibility))->resolve($sku->id, $company->id, 1);
    $bulk = (new BulkPriceResolver($eligibility))->resolveMany([$sku->id], $company->id, 1)->resolved[$sku->id];

    expect($bulk->unitPriceE4)->toBe($single->unitPriceE4)
        ->and($bulk->unitPriceE4)->toBe(7000)
        ->and($bulk->priceSource)->toBe(PriceSource::Tier)
        ->and($bulk->promotionCapped)->toBe($single->promotionCapped)
        ->and($bulk->promotionCapped)->toBeTrue();
});

it('agrees with PriceResolver on falling through to the next rank', function () {
    $sku = Sku::factory()->create();
    $company = Company::factory()->create();
    $customerList = PriceList::factory()->forCompany($company)->create();
    PriceListItem::factory()->for($customerList, 'priceList')->for($sku)->create(['min_base_qty' => 1000, 'unit_price_e4' => 5000]);
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);

    $single = (new PriceResolver)->resolve($sku->id, $company->id, 50);
    $bulk = (new BulkPriceResolver)->resolveMany([$sku->id], $company->id, 50)->resolved[$sku->id];

    expect($bulk->unitPriceE4)->toBe($single->unitPriceE4)
        ->and($bulk->priceSource)->toBe($single->priceSource)
        ->and($bulk->priceSource)->toBe(PriceSource::Base);
});
