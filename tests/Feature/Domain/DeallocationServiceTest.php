<?php

use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\Exceptions\InvalidDeallocationException;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Sku;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function allocateOneForDeallocationTest(Sku $sku, Location $location, OrderLine $orderLine, int $onHand, int $qty, ?Batch $batch = null)
{
    $factory = StockLevel::factory()->for($sku)->for($location);
    if ($batch !== null) {
        $factory = $factory->forBatch($batch);
    }
    $level = $factory->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);

    $company = $orderLine->order->company;

    $line = new AllocationLine($orderLine->id, $sku->id, $location->id, $batch?->id, $qty);
    $allocation = (new AllocationService)->allocate($company->id, 0, [$line])[0];

    return [$allocation, $level];
}

it('releases an allocation: restores allocated_base_qty, order_line counter, and status', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id, 'allocated_base_qty' => 0]);

    [$allocation] = allocateOneForDeallocationTest($sku, $location, $orderLine, 50, 10);
    expect(StockLevel::identity($sku->id, $location->id)->first()->allocated_base_qty)->toBe(10);
    expect($orderLine->fresh()->allocated_base_qty)->toBe(10);

    $result = (new DeallocationService)->deallocate([$allocation->id]);

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe('released')
        ->and($result[0]->released_at)->not->toBeNull();

    $freshLevel = StockLevel::identity($sku->id, $location->id)->first();
    expect($freshLevel->allocated_base_qty)->toBe(0)
        ->and($freshLevel->available_base_qty)->toBe(50)
        ->and($freshLevel->on_hand_base_qty)->toBe(50) // release never touches on_hand
        ->and($freshLevel->version)->toBe(2); // 1 from allocate, 1 from deallocate

    expect($orderLine->fresh()->allocated_base_qty)->toBe(0);
});

it('records a deallocation movement referencing the allocation with a negated base_qty', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    [$allocation] = allocateOneForDeallocationTest($sku, $location, $orderLine, 50, 10);

    (new DeallocationService)->deallocate([$allocation->id]);

    $movements = DB::table('stock_movements')->where('reference_type', 'allocation')->where('reference_id', $allocation->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(2);
    expect($movements[0]->movement_type)->toBe('allocation')->and($movements[0]->base_qty)->toBe(10);
    expect($movements[1]->movement_type)->toBe('deallocation')->and($movements[1]->base_qty)->toBe(-10);
});

it('rejects releasing an already-dispatched allocation and writes nothing', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    [$allocation] = allocateOneForDeallocationTest($sku, $location, $orderLine, 50, 10);
    $allocation->forceFill(['status' => 'dispatched'])->save();

    expect(fn () => (new DeallocationService)->deallocate([$allocation->id]))
        ->toThrow(InvalidDeallocationException::class);

    $freshLevel = StockLevel::identity($sku->id, $location->id)->first();
    expect($freshLevel->allocated_base_qty)->toBe(10) // unchanged
        ->and($allocation->fresh()->status)->toBe('dispatched');
});

it('rejects releasing an already-released allocation', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    [$allocation] = allocateOneForDeallocationTest($sku, $location, $orderLine, 50, 10);
    (new DeallocationService)->deallocate([$allocation->id]);

    expect(fn () => (new DeallocationService)->deallocate([$allocation->id]))
        ->toThrow(InvalidDeallocationException::class);
});

it('rejects an unknown allocation id', function () {
    expect(fn () => (new DeallocationService)->deallocate([999999999]))
        ->toThrow(InvalidDeallocationException::class);
});

it('rejects an empty id list', function () {
    expect(fn () => (new DeallocationService)->deallocate([]))
        ->toThrow(InvalidArgumentException::class);
});

it('releases two allocations sharing a stock identity in a single combined update', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLineA = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id, 'line_no' => 1]);
    $orderLineB = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id, 'line_no' => 2]);

    $level = StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
    $service = new AllocationService;
    $allocA = $service->allocate($company->id, 0, [new AllocationLine($orderLineA->id, $sku->id, $location->id, null, 20)])[0];
    $allocB = $service->allocate($company->id, 0, [new AllocationLine($orderLineB->id, $sku->id, $location->id, null, 30)])[0];

    expect(StockLevel::identity($sku->id, $location->id)->first()->allocated_base_qty)->toBe(50);

    $result = (new DeallocationService)->deallocate([$allocA->id, $allocB->id]);

    expect($result)->toHaveCount(2);
    expect(StockLevel::identity($sku->id, $location->id)->first()->allocated_base_qty)->toBe(0);
    expect($orderLineA->fresh()->allocated_base_qty)->toBe(0)
        ->and($orderLineB->fresh()->allocated_base_qty)->toBe(0);
});

it('releases both batches of a multi-batch allocation and restores each independently', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $batchA = Batch::factory()->for($sku)->create();
    $batchB = Batch::factory()->for($sku)->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    $service = new AllocationService;
    $levelA = StockLevel::factory()->for($sku)->for($location)->forBatch($batchA)->create(['on_hand_base_qty' => 300]);
    $levelB = StockLevel::factory()->for($sku)->for($location)->forBatch($batchB)->create(['on_hand_base_qty' => 132]);

    $allocations = $service->allocate($company->id, 0, [
        new AllocationLine($orderLine->id, $sku->id, $location->id, $batchA->id, 300),
        new AllocationLine($orderLine->id, $sku->id, $location->id, $batchB->id, 132),
    ]);

    expect($orderLine->fresh()->allocated_base_qty)->toBe(432);

    (new DeallocationService)->deallocate(collect($allocations)->pluck('id')->all());

    expect(StockLevel::identity($sku->id, $location->id, $batchA->id)->first()->allocated_base_qty)->toBe(0);
    expect(StockLevel::identity($sku->id, $location->id, $batchB->id)->first()->allocated_base_qty)->toBe(0);
    expect($orderLine->fresh()->allocated_base_qty)->toBe(0);
});

it('locks stock_levels rows in the SAME ascending order as AllocationService, not descending', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $skuLow = Sku::factory()->create();
    $skuHigh = Sku::factory()->create();
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($skuHigh)->create();

    $orderLineA = OrderLine::factory()->for($order)->create(['sku_id' => $skuHigh->id, 'line_no' => 1]);
    $orderLineB = OrderLine::factory()->for($order)->create(['sku_id' => $skuLow->id, 'line_no' => 2]);
    $orderLineC = OrderLine::factory()->for($order)->create(['sku_id' => $skuHigh->id, 'line_no' => 3]);

    $service = new AllocationService;
    [$allocHighTracked] = allocateOneForDeallocationTest($skuHigh, $location, $orderLineA, 10, 1, $batch);
    $levelLow = StockLevel::factory()->for($skuLow)->for($location)->create(['on_hand_base_qty' => 10]);
    $allocLow = $service->allocate($company->id, 0, [new AllocationLine($orderLineB->id, $skuLow->id, $location->id, null, 1)])[0];
    $levelHighUntracked = StockLevel::factory()->for($skuHigh)->for($location)->create(['on_hand_base_qty' => 10]);
    $allocHighUntracked = $service->allocate($company->id, 0, [new AllocationLine($orderLineC->id, $skuHigh->id, $location->id, null, 1)])[0];

    // deliberately passed out of order
    $ids = [$allocHighTracked->id, $allocLow->id, $allocHighUntracked->id];

    DB::enableQueryLog();
    (new DeallocationService)->deallocate($ids);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $lockQueries = array_values(array_filter($log, fn (array $q) => str_contains($q['query'], 'stock_levels') && str_contains($q['query'], 'for update')));

    expect($lockQueries)->toHaveCount(3);
    expect($lockQueries[0]['bindings'][0])->toBe($skuLow->id);
    expect($lockQueries[1]['bindings'][0])->toBe($skuHigh->id);
    expect($lockQueries[2]['bindings'][0])->toBe($skuHigh->id);
    expect($lockQueries[1]['query'])->toContain('is null'); // untracked (NULL batch) before tracked
    expect($lockQueries[2]['bindings'])->toContain($batch->id);
});

it('is fully reversible: allocate then deallocate returns stock_levels to its pre-allocation state', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);
    $level = StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 75, 'allocated_base_qty' => 0]);

    $before = StockLevel::identity($sku->id, $location->id)->first();

    $allocation = (new AllocationService)->allocate($company->id, 0, [
        new AllocationLine($orderLine->id, $sku->id, $location->id, null, 25),
    ])[0];
    (new DeallocationService)->deallocate([$allocation->id]);

    $after = StockLevel::identity($sku->id, $location->id)->first();

    expect($after->on_hand_base_qty)->toBe($before->on_hand_base_qty)
        ->and($after->allocated_base_qty)->toBe($before->allocated_base_qty)
        ->and($after->available_base_qty)->toBe($before->available_base_qty);
});
