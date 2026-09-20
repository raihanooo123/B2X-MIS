<?php

use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
use App\Models\DeliveryZonePostcode;
use App\Models\Order;
use App\Models\TaxClass;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a delivery zone status outside the CHECK list', function () {
    DeliveryZone::factory()->create(['status' => 'hidden']);
})->throws(QueryException::class);

it('rejects a duplicate delivery zone code', function () {
    DeliveryZone::factory()->create(['code' => 'ZONE-A']);
    DeliveryZone::factory()->create(['code' => 'ZONE-A']);
})->throws(QueryException::class);

it('rejects a postcode range where district_to is before district_from', function () {
    DeliveryZonePostcode::factory()->create(['district_from' => 10, 'district_to' => 5]);
})->throws(QueryException::class);

it('rejects a duplicate (area, district_from, district_to) postcode range', function () {
    DeliveryZonePostcode::factory()->create(['area' => 'SW', 'district_from' => 1, 'district_to' => 10]);
    DeliveryZonePostcode::factory()->create(['area' => 'SW', 'district_from' => 1, 'district_to' => 10]);
})->throws(QueryException::class);

it('allows the same range to be reused if it is not identical', function () {
    DeliveryZonePostcode::factory()->create(['area' => 'SW', 'district_from' => 1, 'district_to' => 10]);
    $second = DeliveryZonePostcode::factory()->create(['area' => 'SW', 'district_from' => 5, 'district_to' => 15]);

    expect($second->exists)->toBeTrue();
});

it('cascades delete of postcodes when the owning zone is deleted', function () {
    $zone = DeliveryZone::factory()->create();
    $postcode = DeliveryZonePostcode::factory()->for($zone, 'deliveryZone')->create();

    $zone->delete();

    expect(DeliveryZonePostcode::find($postcode->id))->toBeNull();
});

it('rejects a delivery rate status outside the CHECK list', function () {
    DeliveryRate::factory()->create(['status' => 'expired']);
})->throws(QueryException::class);

it('rejects a delivery rate method outside the CHECK list', function () {
    DeliveryRate::factory()->create(['method' => 'teleport']);
})->throws(QueryException::class);

it('rejects a negative price_net_minor', function () {
    DeliveryRate::factory()->create(['price_net_minor' => -1]);
})->throws(QueryException::class);

it('rejects an empty weight_range', function () {
    DeliveryRate::factory()->create(['weight_range' => '[5000,5000)']);
})->throws(QueryException::class);

it('rejects a delivery rate referencing a nonexistent tax_class_id', function () {
    DeliveryRate::factory()->create(['tax_class_id' => 999999999]);
})->throws(QueryException::class);

it('rejects two overlapping active rates for the same zone, method and weight band', function () {
    $zone = DeliveryZone::factory()->create();
    DeliveryRate::factory()->for($zone, 'deliveryZone')->weightBand(0, 5000)->create();

    DeliveryRate::factory()->for($zone, 'deliveryZone')->weightBand(2000, 8000)->create();
})->throws(QueryException::class);

it('allows non-overlapping weight bands for the same zone and method', function () {
    $zone = DeliveryZone::factory()->create();
    DeliveryRate::factory()->for($zone, 'deliveryZone')->weightBand(0, 5000)->create();
    $heavyBand = DeliveryRate::factory()->for($zone, 'deliveryZone')->weightBand(5000, null)->create();

    expect($heavyBand->exists)->toBeTrue();
});

it('allows the same weight band for different methods in the same zone', function () {
    $zone = DeliveryZone::factory()->create();
    DeliveryRate::factory()->for($zone, 'deliveryZone')->method('parcel')->weightBand(0, 5000)->create();
    $pallet = DeliveryRate::factory()->for($zone, 'deliveryZone')->method('pallet')->weightBand(0, 5000)->create();

    expect($pallet->exists)->toBeTrue();
});

it('allows a new active rate once the prior active window has genuinely closed (not just status)', function () {
    $zone = DeliveryZone::factory()->create();
    // Still 'active', but its validity window ended before "now" — the
    // EXCLUDE's own range logic, not the status filter, must allow this.
    DeliveryRate::factory()->for($zone, 'deliveryZone')
        ->weightBand(0, 5000)
        ->forPeriod(now()->subYear(), now()->subDay())
        ->create(['status' => 'active']);

    $current = DeliveryRate::factory()->for($zone, 'deliveryZone')->weightBand(0, 5000)->create();

    expect($current->exists)->toBeTrue();
});

it('resolves a delivery rate to its tax class', function () {
    $taxClass = TaxClass::factory()->standard()->create();
    $rate = DeliveryRate::factory()->create(['tax_class_id' => $taxClass->id]);

    expect($rate->taxClass->id)->toBe($taxClass->id);
});

it('wires orders.delivery_zone_id to a real delivery_zones row', function () {
    $zone = DeliveryZone::factory()->create();
    $order = Order::factory()->withDeliveryZone($zone)->create();

    expect($order->fresh()->delivery_zone_id)->toBe($zone->id)
        ->and($order->deliveryZone->id)->toBe($zone->id)
        ->and($zone->orders()->first()->id)->toBe($order->id);
});

it('rejects an order referencing a nonexistent delivery_zone_id', function () {
    Order::factory()->create(['delivery_zone_id' => 999999999]);
})->throws(QueryException::class);

it('resolves delivery zone relationships: zone -> postcodes and zone -> rates', function () {
    $zone = DeliveryZone::factory()->create();
    DeliveryZonePostcode::factory()->count(2)->for($zone, 'deliveryZone')->create();
    DeliveryRate::factory()->for($zone, 'deliveryZone')->create();

    expect($zone->postcodes()->count())->toBe(2)
        ->and($zone->rates()->count())->toBe(1);
});
