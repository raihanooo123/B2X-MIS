<?php

use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
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

function makeAllocationLine(OrderLine $line, StockLevel $level, int $baseQty): AllocationLine
{
    return new AllocationLine($line->id, $level->sku_id, $level->location_id, $level->batch_id, $baseQty);
}

it('allocates stock: creates the allocation, an allocation movement, and increments both counters', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $level = StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 50, 'allocated_base_qty' => 0]);
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id, 'allocated_base_qty' => 0]);

    $result = (new AllocationService)->allocate($company->id, 0, [
        makeAllocationLine($orderLine, $level, 10),
    ]);

    expect($result)->toHaveCount(1);
    $allocation = $result[0];
    expect($allocation->status)->toBe('allocated')
        ->and($allocation->base_qty)->toBe(10);

    $movement = DB::table('stock_movements')->where('reference_type', 'allocation')->where('reference_id', $allocation->id)->first();
    expect($movement)->not->toBeNull()
        ->and($movement->movement_type)->toBe('allocation')
        ->and($movement->base_qty)->toBe(10);

    $freshLevel = StockLevel::identity($sku->id, $location->id)->first();
    expect($freshLevel->allocated_base_qty)->toBe(10)
        ->and($freshLevel->available_base_qty)->toBe(40)
        ->and($freshLevel->on_hand_base_qty)->toBe(50) // §7.2: allocation never reduces on_hand
        ->and($freshLevel->version)->toBe(1)
        ->and($freshLevel->last_movement_id)->toBe($movement->id);

    expect($orderLine->fresh()->allocated_base_qty)->toBe(10);
});

it('throws and writes nothing when stock is insufficient', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $level = StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 5, 'allocated_base_qty' => 0]);
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    try {
        (new AllocationService)->allocate($company->id, 0, [
            makeAllocationLine($orderLine, $level, 10),
        ]);
        expect(false)->toBeTrue('Expected InsufficientStockException to be thrown.');
    } catch (InsufficientStockException $e) {
        expect($e->shortfalls)->toHaveCount(1);
        expect($e->shortfalls[0]->requestedBaseQty)->toBe(10)
            ->and($e->shortfalls[0]->availableBaseQty)->toBe(5);
    }

    expect(DB::table('stock_allocations')->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(StockLevel::identity($sku->id, $location->id)->first()->allocated_base_qty)->toBe(0);
});

it('treats a missing stock_levels row as zero availability', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    $line = new AllocationLine($orderLine->id, $sku->id, $location->id, null, 1);

    expect(fn () => (new AllocationService)->allocate($company->id, 0, [$line]))
        ->toThrow(InsufficientStockException::class);

    expect(DB::table('stock_allocations')->count())->toBe(0);
});

it('throws on insufficient credit before taking any stock lock', function () {
    $company = Company::factory()->create([
        'credit_limit_minor' => 1000, 'credit_used_minor' => 900, 'credit_held_minor' => 0, 'account_balance_minor' => 0,
    ]);
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $level = StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 500]);
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    try {
        (new AllocationService)->allocate($company->id, 5000, [
            makeAllocationLine($orderLine, $level, 10),
        ]);
        expect(false)->toBeTrue('Expected InsufficientCreditException to be thrown.');
    } catch (InsufficientCreditException $e) {
        expect($e->availableCreditMinor)->toBe(100)
            ->and($e->requiredCreditMinor)->toBe(5000);
    }

    // ample stock existed — proof the credit check happened first and no
    // stock lock / write was ever attempted (Doc 02 §11.1: "a rejected
    // order holds nothing")
    expect(DB::table('stock_allocations')->count())->toBe(0);
    expect(StockLevel::identity($sku->id, $location->id)->first()->allocated_base_qty)->toBe(0);
});

it('never lets account_balance_minor extend the credit gate, per Doc 05.4 §7.5A', function () {
    // Doc 05.4 §7.5A: "It does not increase available credit... because
    // the £500 is already their money" — the balance is applied as
    // PAYMENT against the order total elsewhere, never folded into this
    // check. A £10,000 limit with a £500 balance still only clears
    // exactly £10,000 of required credit here, not £10,500 — proving
    // the same balance can't be counted once as headroom AND again
    // later when it's actually applied as payment.
    $company = Company::factory()->create([
        'credit_limit_minor' => 1000000, 'credit_used_minor' => 0, 'credit_held_minor' => 0, 'account_balance_minor' => 50000,
    ]);
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $level = StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 10]);
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    // exactly the limit, ignoring the balance entirely — succeeds
    $result = (new AllocationService)->allocate($company->id, 1000000, [
        makeAllocationLine($orderLine, $level, 1),
    ]);
    expect($result)->toHaveCount(1);

    // one minor unit over the limit — the balance does NOT cover the
    // gap, even though limit + balance would
    $orderLineB = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id, 'line_no' => 2]);
    expect(fn () => (new AllocationService)->allocate($company->id, 1000001, [
        makeAllocationLine($orderLineB, $level, 1),
    ]))->toThrow(InsufficientCreditException::class);
});

it('satisfies one order line from two different batches with two allocation rows', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    $order = Order::factory()->for($company)->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $batchA = Batch::factory()->for($sku)->create();
    $batchB = Batch::factory()->for($sku)->create();
    $levelA = StockLevel::factory()->for($sku)->for($location)->forBatch($batchA)->create(['on_hand_base_qty' => 300]);
    $levelB = StockLevel::factory()->for($sku)->for($location)->forBatch($batchB)->create(['on_hand_base_qty' => 132]);
    $orderLine = OrderLine::factory()->for($order)->create(['sku_id' => $sku->id]);

    $result = (new AllocationService)->allocate($company->id, 0, [
        makeAllocationLine($orderLine, $levelA, 300),
        makeAllocationLine($orderLine, $levelB, 132),
    ]);

    expect($result)->toHaveCount(2)
        ->and(collect($result)->pluck('order_line_id')->unique()->all())->toBe([$orderLine->id]);
    expect($orderLine->fresh()->allocated_base_qty)->toBe(432);
});

it('locks stock_levels rows in ascending (sku_id, location_id, batch_id NULLS FIRST) order, matching Doc 02 §11.1', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    $order = Order::factory()->for($company)->create();
    $skuLow = Sku::factory()->create();
    $skuHigh = Sku::factory()->create();
    $location = Location::factory()->create();
    $batch = Batch::factory()->for($skuHigh)->create();

    // same sku_id+location_id: an untracked (NULL batch) row and a
    // batch-tracked row, to prove NULLS FIRST specifically
    $levelHighTracked = StockLevel::factory()->for($skuHigh)->for($location)->forBatch($batch)->create(['on_hand_base_qty' => 10]);
    $levelHighUntracked = StockLevel::factory()->for($skuHigh)->for($location)->create(['on_hand_base_qty' => 10]);
    $levelLow = StockLevel::factory()->for($skuLow)->for($location)->create(['on_hand_base_qty' => 10]);

    // one order line per stock identity — stock_allocations_identity_uq
    // is keyed on (order_line_id, location_id, batch_id), not sku_id, so
    // reusing one order line across identities that share a location and
    // batch_id would collide regardless of sku_id
    $orderLineA = OrderLine::factory()->for($order)->create(['sku_id' => $skuHigh->id, 'line_no' => 1]);
    $orderLineB = OrderLine::factory()->for($order)->create(['sku_id' => $skuLow->id, 'line_no' => 2]);
    $orderLineC = OrderLine::factory()->for($order)->create(['sku_id' => $skuHigh->id, 'line_no' => 3]);

    // deliberately passed out of order: high-sku-tracked, low-sku, high-sku-untracked
    $lines = [
        makeAllocationLine($orderLineA, $levelHighTracked, 1),
        makeAllocationLine($orderLineB, $levelLow, 1),
        makeAllocationLine($orderLineC, $levelHighUntracked, 1),
    ];

    DB::enableQueryLog();
    (new AllocationService)->allocate($company->id, 0, $lines);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $lockQueries = array_values(array_filter($log, fn (array $q) => str_contains($q['query'], 'stock_levels') && str_contains($q['query'], 'for update')));

    expect($lockQueries)->toHaveCount(3);
    // expected order: skuLow (lowest sku_id) first, then skuHigh's two
    // rows with the untracked (NULL batch) one before the tracked one
    expect($lockQueries[0]['bindings'][0])->toBe($skuLow->id);
    expect($lockQueries[1]['bindings'][0])->toBe($skuHigh->id);
    expect($lockQueries[2]['bindings'][0])->toBe($skuHigh->id);
    // query 1 (index 1) is the untracked one: its SQL has "batch_id" is null,
    // query 2 (index 2) is the tracked one: it binds a batch_id value
    expect($lockQueries[1]['query'])->toContain('is null');
    expect($lockQueries[2]['bindings'])->toContain($batch->id);
});

it('rejects an empty line list', function () {
    $company = Company::factory()->create();

    expect(fn () => (new AllocationService)->allocate($company->id, 0, []))
        ->toThrow(InvalidArgumentException::class);
});
