<?php

use App\Domain\Pricing\Exceptions\NoBasePriceListException;
use App\Domain\Pricing\Exceptions\NotPurchasableException;
use App\Domain\Pricing\Exceptions\PriceUnavailableForCurrencyException;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PromotionEligibilityResolver;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceTier;
use App\Models\Sku;
use App\Models\SkuCost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Grants eligibility for exactly the price_list ids it's constructed
 * with — a real PromotionEligibilityResolver would derive this from
 * `promotions`/`promotion_rules` (02 §14.8, DRAFT), which don't exist
 * yet. Standing in for that here is what makes rank-3 testable at all.
 */
function eligibleFor(int ...$priceListIds): PromotionEligibilityResolver
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

// -----------------------------------------------------------------
// §12 fixture 1 — guest, base price, qty 1
// -----------------------------------------------------------------

it('resolves a guest to the base price list', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create([
        'min_base_qty' => 1,
        'unit_price_e4' => 9800,
    ]);

    $resolved = (new PriceResolver)->resolve($sku->id, null, 1);

    expect($resolved->unitPriceE4)->toBe(9800)
        ->and($resolved->priceSource)->toBe(PriceSource::Base)
        ->and($resolved->appliedBreakQty)->toBe(1)
        ->and($resolved->promotionCapped)->toBeFalse();
});

// -----------------------------------------------------------------
// §12 fixture 2 — tier customer, break selection
// -----------------------------------------------------------------

it('resolves a tier customer to the greatest qualifying break', function () {
    $sku = Sku::factory()->create();
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    PriceList::factory()->create(['scope' => 'base']); // present but must lose to tier
    $tierList = PriceList::factory()->forTier($tier)->create();
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 144, 'unit_price_e4' => 9200]);
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 1440, 'unit_price_e4' => 8600]);

    $resolved = (new PriceResolver)->resolve($sku->id, $company->id, 200);

    expect($resolved->unitPriceE4)->toBe(9200)
        ->and($resolved->priceSource)->toBe(PriceSource::Tier)
        ->and($resolved->appliedBreakQty)->toBe(144)
        ->and($resolved->nextBreakQty)->toBe(1440)
        ->and($resolved->nextBreakUnitPriceE4)->toBe(8600);
});

it('reports no next break when already at the best break', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1440, 'unit_price_e4' => 8600]);

    $resolved = (new PriceResolver)->resolve($sku->id, null, 2000);

    expect($resolved->unitPriceE4)->toBe(8600)
        ->and($resolved->nextBreakQty)->toBeNull()
        ->and($resolved->nextBreakUnitPriceE4)->toBeNull();
});

// -----------------------------------------------------------------
// §12 fixture 3 — contract beats promotion; §4.2 precedence
// -----------------------------------------------------------------

it('resolves a contract price over an active promotion for the same company', function () {
    $sku = Sku::factory()->create();
    $company = Company::factory()->create();
    $contractList = PriceList::factory()->forCompany($company, hasContract: true)->create();
    PriceListItem::factory()->for($contractList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 5000]);
    $promoList = PriceList::factory()->forPromotion(promotionId: 1)->create();
    PriceListItem::factory()->for($promoList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 6000]);

    $resolver = new PriceResolver(eligibleFor($promoList->id));
    $resolved = $resolver->resolve($sku->id, $company->id, 1);

    expect($resolved->unitPriceE4)->toBe(5000)
        ->and($resolved->priceSource)->toBe(PriceSource::Contract);
});

// -----------------------------------------------------------------
// §12 fixture 4 — promotion beats tier
// -----------------------------------------------------------------

it('resolves a promotion price over tier pricing when the promotion is cheaper', function () {
    $sku = Sku::factory()->create();
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    $tierList = PriceList::factory()->forTier($tier)->create();
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9000]);
    $promoList = PriceList::factory()->forPromotion(promotionId: 2)->create();
    PriceListItem::factory()->for($promoList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 7000]);

    $resolver = new PriceResolver(eligibleFor($promoList->id));
    $resolved = $resolver->resolve($sku->id, $company->id, 1);

    expect($resolved->unitPriceE4)->toBe(7000)
        ->and($resolved->priceSource)->toBe(PriceSource::Promotion)
        ->and($resolved->promotionCapped)->toBeFalse();
});

// -----------------------------------------------------------------
// §12 fixture 5 — promotion worse than tier → cap fires
// -----------------------------------------------------------------

it('caps a promotion that would be more expensive than tier pricing', function () {
    $sku = Sku::factory()->create();
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    $tierList = PriceList::factory()->forTier($tier)->create();
    PriceListItem::factory()->for($tierList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 7000]);
    $promoList = PriceList::factory()->forPromotion(promotionId: 3)->create();
    PriceListItem::factory()->for($promoList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9000]);

    $resolver = new PriceResolver(eligibleFor($promoList->id));
    $resolved = $resolver->resolve($sku->id, $company->id, 1);

    expect($resolved->unitPriceE4)->toBe(7000)
        ->and($resolved->priceSource)->toBe(PriceSource::Tier)
        ->and($resolved->promotionCapped)->toBeTrue();
});

// -----------------------------------------------------------------
// §12 fixture 6 — a list with only a high break must not block fallthrough
// -----------------------------------------------------------------

it('falls through to the next rank when the winning-rank list has no qualifying break', function () {
    $sku = Sku::factory()->create();
    $company = Company::factory()->create();
    $customerList = PriceList::factory()->forCompany($company)->create();
    PriceListItem::factory()->for($customerList, 'priceList')->for($sku)->create(['min_base_qty' => 1000, 'unit_price_e4' => 5000]);
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);

    $resolved = (new PriceResolver)->resolve($sku->id, $company->id, 50);

    expect($resolved->unitPriceE4)->toBe(9800)
        ->and($resolved->priceSource)->toBe(PriceSource::Base);
});

// -----------------------------------------------------------------
// §12 fixture 7 — sub-penny precision survives exactly
// -----------------------------------------------------------------

it('preserves sub-penny unit prices exactly, the §3.3 case', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1440, 'unit_price_e4' => 9212]);

    $resolved = (new PriceResolver)->resolve($sku->id, null, 1440);

    expect($resolved->unitPriceE4)->toBe(9212);
});

// -----------------------------------------------------------------
// Precedence completeness / tie-breaking within a rank
// -----------------------------------------------------------------

it('breaks ties within a rank on priority DESC then id DESC', function () {
    // Only 'promotion' scope has no EXCLUDE overlap constraint (02 §6.3) —
    // base/tier/company lists can never legitimately coexist active and
    // overlapping, so this tie-break is only reachable here.
    $sku = Sku::factory()->create();
    // Base is deliberately the most expensive of the three so the §4.5
    // promotion cap never fires and doesn't interfere with this test.
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 3000]);
    $lowPriority = PriceList::factory()->forPromotion(promotionId: 10)->create(['priority' => 100]);
    PriceListItem::factory()->for($lowPriority, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 1000]);
    $highPriority = PriceList::factory()->forPromotion(promotionId: 11)->create(['priority' => 200]);
    PriceListItem::factory()->for($highPriority, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 2000]);

    $resolver = new PriceResolver(eligibleFor($lowPriority->id, $highPriority->id));
    $resolved = $resolver->resolve($sku->id, null, 1);

    expect($resolved->unitPriceE4)->toBe(2000)
        ->and($resolved->priceListId)->toBe($highPriority->id)
        ->and($resolved->promotionCapped)->toBeFalse();
});

it('resolves company-with-contract above company-without-contract', function () {
    $sku = Sku::factory()->create();
    $company = Company::factory()->create();
    $noContract = PriceList::factory()->forCompany($company, hasContract: false)->create();
    PriceListItem::factory()->for($noContract, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 8000]);
    $contract = PriceList::factory()->forCompany($company, hasContract: true)->create();
    PriceListItem::factory()->for($contract, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 6000]);

    $resolved = (new PriceResolver)->resolve($sku->id, $company->id, 1);

    expect($resolved->unitPriceE4)->toBe(6000)
        ->and($resolved->priceSource)->toBe(PriceSource::Contract);
});

// -----------------------------------------------------------------
// Guest isolation — a company-scoped list for someone else must never leak
// -----------------------------------------------------------------

it('never resolves a guest to a company-scoped or tier-scoped list', function () {
    $sku = Sku::factory()->create();
    $otherCompany = Company::factory()->create();
    $companyList = PriceList::factory()->forCompany($otherCompany)->create();
    PriceListItem::factory()->for($companyList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 1000]);
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);

    $resolved = (new PriceResolver)->resolve($sku->id, null, 1);

    expect($resolved->unitPriceE4)->toBe(9800)
        ->and($resolved->priceSource)->toBe(PriceSource::Base);
});

// -----------------------------------------------------------------
// Cost snapshot (§11)
// -----------------------------------------------------------------

it('resolves the current unit cost alongside the price', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    SkuCost::factory()->for($sku)->asOf(now()->subDays(10))->create(['fob_e4' => 4000, 'freight_e4' => 0, 'duty_e4' => 0, 'other_e4' => 0]);
    SkuCost::factory()->for($sku)->asOf(now()->subDay())->create(['fob_e4' => 5000, 'freight_e4' => 200, 'duty_e4' => 0, 'other_e4' => 0]);

    $resolved = (new PriceResolver)->resolve($sku->id, null, 1);

    expect($resolved->unitCostE4)->toBe(5200);
});

it('leaves unit cost null when the SKU has no cost history', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);

    $resolved = (new PriceResolver)->resolve($sku->id, null, 1);

    expect($resolved->unitCostE4)->toBeNull();
});

// -----------------------------------------------------------------
// §4.6 failure modes
// -----------------------------------------------------------------

it('throws InvalidArgumentException for a zero base_qty', function () {
    $sku = Sku::factory()->create();

    expect(fn () => (new PriceResolver)->resolve($sku->id, null, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException for a negative base_qty', function () {
    $sku = Sku::factory()->create();

    expect(fn () => (new PriceResolver)->resolve($sku->id, null, -5))
        ->toThrow(InvalidArgumentException::class);
});

it('throws NotPurchasableException for a non-active SKU', function () {
    $sku = Sku::factory()->create(['status' => 'discontinued']);

    expect(fn () => (new PriceResolver)->resolve($sku->id, null, 1))
        ->toThrow(NotPurchasableException::class);
});

it('throws NoBasePriceListException when no base list exists in any currency', function () {
    $sku = Sku::factory()->create();

    expect(fn () => (new PriceResolver)->resolve($sku->id, null, 1))
        ->toThrow(NoBasePriceListException::class);
});

it('throws PriceUnavailableForCurrencyException when a base list exists only in another currency', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base', 'currency' => 'EUR']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);

    expect(fn () => (new PriceResolver)->resolve($sku->id, null, 1, currency: 'GBP'))
        ->toThrow(PriceUnavailableForCurrencyException::class);
});

// -----------------------------------------------------------------
// Property-style: monotonicity within one list
// -----------------------------------------------------------------

it('never increases unit price as base_qty increases within one list', function () {
    $sku = Sku::factory()->create();
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 9800]);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 144, 'unit_price_e4' => 9200]);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1440, 'unit_price_e4' => 8600]);

    $resolver = new PriceResolver;
    $prices = collect([1, 50, 144, 500, 1440, 5000])
        ->map(fn (int $qty) => $resolver->resolve($sku->id, null, $qty)->unitPriceE4);

    expect($prices->values()->all())->toBe($prices->sort()->reverse()->values()->all());
});
