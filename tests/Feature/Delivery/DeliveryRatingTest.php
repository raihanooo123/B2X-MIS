<?php

use App\Domain\Delivery\CarriageCalculator;
use App\Domain\Delivery\ThresholdEvaluator;
use App\Domain\Delivery\ZoneResolver;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderSpendBreak;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 05.6 §11 — zone resolution fixtures, rate fixtures and thresholds, against
 * the launch zones and rates from DeliveryZoneSeeder. Mainland parcel bands:
 * [0, 2 kg) £6.50 · [2, 10 kg) £8.95 · [10, 20 kg) £12.50 · [20 kg, ∞)
 * £16.00 + 45p/kg; carriage-paid over £500 (mainland) and £1,200 (Highlands).
 */
beforeEach(function () {
    $this->seed(DeliveryZoneSeeder::class);
});

dataset('05.6 §11 zone fixtures', [
    'baseline' => ['EC1A 1BB', 'GB_MAINLAND'],
    'whole-area rule' => ['BT9 5AA', 'GB_NI'],
    'must not match Isle of Wight' => ['PO3 5AB', 'GB_MAINLAND'],
    'range rule beats area fallback' => ['PO31 7AA', 'GB_IOW'],
    'range boundary, upper' => ['KW14 8XX', 'GB_HIGHLANDS'],
    'range boundary, adjacent zone' => ['KW15 1AA', 'GB_SCOT_ISLES'],
    'Highlands whole-area rule' => ['IV51 9AA', 'GB_HIGHLANDS'],
    'Islands whole-area rule' => ['HS1 2AA', 'GB_SCOT_ISLES'],
    'manual-quote zone' => ['TR22 0AA', 'GB_SCILLY'],
    'outside UK VAT' => ['JE2 3AA', 'GB_CHANNEL'],
    'unknown, flagged' => ['XX99 9XX', 'GB_MAINLAND'],
]);

it('resolves each 05.6 §11 postcode to its zone', function (string $postcode, string $zone) {
    $resolution = (new ZoneResolver)->resolve($postcode, 'GB');

    expect($resolution->zone?->code)->toBe($zone)
        // Only the unknown area is flagged; the mainland fallback is not.
        ->and($resolution->recognised)->toBe($postcode !== 'XX99 9XX');
})->with('05.6 §11 zone fixtures');

it('resolves regardless of spacing and case', function () {
    expect((new ZoneResolver)->resolve('po317aa', 'GB')->zone?->code)->toBe('GB_IOW')
        ->and((new ZoneResolver)->resolve(' kw15  1aa ', 'GB')->zone?->code)->toBe('GB_SCOT_ISLES');
});

it('selects the higher band at exactly its lower bound', function () {
    $mainland = DeliveryZone::query()->where('code', 'GB_MAINLAND')->sole();
    $calc = new CarriageCalculator;

    $below = $calc->rate($mainland->id, 'parcel', 1999);
    $at = $calc->rate($mainland->id, 'parcel', 2000);

    expect($below?->netMinor())->toBe(650)
        ->and($at?->bandLowerG)->toBe(2000)
        ->and($at?->netMinor())->toBe(895);
});

it('computes the top band surcharge per extra kg with one half-up rounding', function () {
    $mainland = DeliveryZone::query()->where('code', 'GB_MAINLAND')->sole();

    // 5,500 g over 20 kg × 45p/kg = 247.5p → 248p, rounded once.
    $rated = (new CarriageCalculator)->rate($mainland->id, 'parcel', 25500);

    expect($rated?->priceNetMinor)->toBe(1600)
        ->and($rated?->surchargeNetMinor)->toBe(248)
        ->and($rated?->netMinor())->toBe(1848);
});

it('has no rate for a manual-quote zone', function () {
    $scilly = DeliveryZone::query()->where('code', 'GB_SCILLY')->sole();

    expect((new CarriageCalculator)->rate($scilly->id, 'parcel', 1000))->toBeNull();
});

it("lets a zone's own carriage-paid threshold beat the global one", function () {
    SystemConfiguration::factory()->create(['config_key' => ThresholdEvaluator::CARRIAGE_PAID_KEY, 'value_int' => 30000]);
    $thresholds = new ThresholdEvaluator;

    $mainland = DeliveryZone::query()->where('code', 'GB_MAINLAND')->sole();
    $highlands = DeliveryZone::query()->where('code', 'GB_HIGHLANDS')->sole();

    expect($thresholds->carriagePaidThresholdNetMinor($mainland, null))->toBe(30000)
        ->and($thresholds->carriagePaidThresholdNetMinor($highlands, null))->toBe(120000);
});

it('falls back to £500 carriage-paid when nothing is configured', function () {
    $mainland = DeliveryZone::query()->where('code', 'GB_MAINLAND')->sole();

    expect((new ThresholdEvaluator)->carriagePaidThresholdNetMinor($mainland, null))->toBe(50000);
});

it('blocks below the minimum order and permits exactly the minimum', function () {
    SystemConfiguration::factory()->create(['config_key' => ThresholdEvaluator::MINIMUM_ORDER_KEY, 'value_int' => 10000]);
    $thresholds = new ThresholdEvaluator;

    expect($thresholds->belowMinimum(9999, null))->toBeTrue()
        ->and($thresholds->belowMinimum(10000, null))->toBeFalse();
});

it('has no minimum order when none is configured', function () {
    expect((new ThresholdEvaluator)->belowMinimum(1, null))->toBeFalse();
});

/*
 * Through checkout: preview and POST /checkout.
 */

/**
 * A buyer, a £100-a-unit SKU (single-unit packs) at 20% VAT or the given
 * goods rate, and stock. `$packWeightG` null leaves the pack and SKU
 * without weight data.
 */
function deliveryBuyer(?int $packWeightG = 500, int $goodsRateBp = 2000): User
{
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $location = Location::factory()->default()->create();
    $baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => $goodsRateBp]);

    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    Pack::factory()->for($sku)->create(['base_units' => 1, 'gross_weight_g' => $packWeightG]);
    PriceListItem::factory()->for($baseList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 1_000_000]);
    StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
    test()->deliverySku = $sku;

    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 10_000_000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    Address::factory()->default()->delivery()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    return $user;
}

/** @return array<string, mixed> */
function deliveryPreview(User $user, int $packQty, string $postcode = 'E1 6AN'): array
{
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->deliverySku->public_id, 'pack_qty' => $packQty])->assertSuccessful();

    return test()->actingAs($user)
        ->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => $postcode])
        ->assertOk()
        ->json();
}

it('charges carriage VAT at the carriage tax class rate, not the goods rate', function () {
    // Zero-rated goods: £300 net, no VAT. Carriage: 1.5 kg → £6.50 + 20%.
    $preview = deliveryPreview(deliveryBuyer(goodsRateBp: 0), 3);

    expect($preview['delivery']['status'])->toBe('rated')
        ->and($preview['delivery']['zone'])->toBe('GB_MAINLAND')
        ->and($preview['delivery']['method'])->toBe('parcel')
        ->and($preview['delivery']['weight_g'])->toBe(1500)
        ->and($preview['delivery']['tax_rate_bp'])->toBe(2000)
        ->and($preview['delivery']['shipping_net_minor'])->toBe(650)
        ->and($preview['delivery']['shipping_tax_minor'])->toBe(130)
        ->and($preview['tax_minor'])->toBe(130)
        ->and($preview['total_gross_minor'])->toBe(30000 + 650 + 130);
});

it('makes carriage free at the carriage-paid threshold', function () {
    // 5 × £100 = £500 net: exactly the mainland threshold.
    $preview = deliveryPreview(deliveryBuyer(), 5);

    expect($preview['delivery']['status'])->toBe('free')
        ->and($preview['delivery']['shipping_net_minor'])->toBe(0)
        ->and($preview['total_gross_minor'])->toBe(50000 + 10000);
});

it('measures carriage-paid on the post-spend-break subtotal, so a spend break can restore carriage', function () {
    // £500 before the break; 3% off takes it to £485 — under the threshold.
    OrderSpendBreak::factory()->create(['min_subtotal_minor' => 50000, 'discount_rate_bp' => 300]);

    $preview = deliveryPreview(deliveryBuyer(), 5);

    expect($preview['subtotal_net_minor'])->toBe(48500)
        ->and($preview['delivery']['status'])->toBe('rated')
        // 5 × 500 g = 2.5 kg → the £8.95 band.
        ->and($preview['delivery']['shipping_net_minor'])->toBe(895)
        ->and($preview['delivery']['shortfall_to_free_minor'])->toBe(1500);
});

it('quotes by hand, never £0, when weight data is missing', function () {
    $preview = deliveryPreview(deliveryBuyer(packWeightG: null), 3);

    expect($preview['delivery']['status'])->toBe('manual_quote')
        ->and($preview['delivery']['reason'])->toBe('missing_weight')
        ->and(collect($preview['blockers'])->pluck('code')->all())->toContain('carriage_quote_required');
});

it('quotes by hand for a manual-quote zone', function () {
    $preview = deliveryPreview(deliveryBuyer(), 3, 'TR22 0AA');

    expect($preview['delivery']['status'])->toBe('manual_quote')
        ->and($preview['delivery']['reason'])->toBe('zone_manual_quote')
        ->and(collect($preview['blockers'])->pluck('code')->all())->toContain('carriage_quote_required');
});

it('stores the resolved zone, rate, method and carriage VAT on the order', function () {
    $user = deliveryBuyer();
    $preview = deliveryPreview($user, 3, 'PO31 7AA');

    test()->actingAs($user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
        'payment_method' => 'bacs',
        'expected_total_gross_minor' => $preview['total_gross_minor'],
        'delivery_address' => ['contact_name' => 'Sam Lee', 'line1' => '1 Esplanade', 'city' => 'Cowes', 'postcode' => 'PO31 7AA', 'country_code' => 'GB'],
    ])->assertCreated();

    $order = Order::query()->sole();

    // Isle of Wight, 1.5 kg parcel: £11.50 + 20%.
    expect($order->delivery_zone_id)->toBe(DeliveryZone::query()->where('code', 'GB_IOW')->value('id'))
        ->and($order->delivery_rate_id)->not->toBeNull()
        ->and($order->delivery_method)->toBe('parcel')
        ->and($order->shipping_net_minor)->toBe(1150)
        ->and($order->shipping_tax_rate_bp)->toBe(2000)
        ->and($order->shipping_tax_minor)->toBe(230)
        ->and($order->total_gross_minor)->toBe($preview['total_gross_minor']);
});

it('refuses to place an order whose carriage is unknown', function () {
    $user = deliveryBuyer(packWeightG: null);
    $preview = deliveryPreview($user, 3);

    test()->actingAs($user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
        'payment_method' => 'bacs',
        'expected_total_gross_minor' => $preview['total_gross_minor'],
        'delivery_address' => ['contact_name' => 'Sam Lee', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'],
    ])->assertStatus(422);

    expect(Order::query()->count())->toBe(0);
});
