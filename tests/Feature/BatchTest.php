<?php

use App\Models\Batch;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects a status outside the CHECK list', function () {
    Batch::factory()->create(['status' => 'in_transit']);
})->throws(QueryException::class);

it('rejects expires_on before manufactured_on', function () {
    Batch::factory()->expiring(now(), now()->subYear())->create();
})->throws(QueryException::class);

it('accepts expires_on on or after manufactured_on', function () {
    $batch = Batch::factory()->expiring(now()->subMonth(), now()->addYear())->create();

    expect($batch->exists)->toBeTrue();
});

it('rejects a duplicate batch_code for the same sku', function () {
    $sku = Sku::factory()->create();
    Batch::factory()->for($sku)->create(['batch_code' => 'LOT-001']);
    Batch::factory()->for($sku)->create(['batch_code' => 'LOT-001']);
})->throws(QueryException::class);

it('allows the same batch_code across different skus', function () {
    Batch::factory()->create(['batch_code' => 'LOT-001']);
    $second = Batch::factory()->create(['batch_code' => 'LOT-001']);

    expect($second->exists)->toBeTrue();
});

it('rejects a batch referencing a nonexistent sku_cost_id', function () {
    Batch::factory()->create(['sku_cost_id' => 999999999]);
})->throws(QueryException::class);

it('wires batches.sku_cost_id to a real sku_costs row', function () {
    $skuCost = SkuCost::factory()->create();
    $batch = Batch::factory()->create(['sku_cost_id' => $skuCost->id]);

    expect($batch->skuCost->id)->toBe($skuCost->id)
        ->and($skuCost->batches()->first()->id)->toBe($batch->id);
});

it('resolves Sku -> batches', function () {
    $sku = Sku::factory()->create();
    Batch::factory()->for($sku)->count(2)->create();

    expect($sku->batches()->count())->toBe(2);
});

it('orders FEFO with NULLS LAST — batches with no expiry sort after dated ones', function () {
    $sku = Sku::factory()->create();
    $noExpiry = Batch::factory()->for($sku)->create(['batch_code' => 'A']);
    $expiresLater = Batch::factory()->for($sku)->expiring(now(), now()->addYear())->create(['batch_code' => 'B']);
    $expiresSoon = Batch::factory()->for($sku)->expiring(now(), now()->addWeek())->create(['batch_code' => 'C']);

    $order = DB::select(
        'select id from batches where sku_id = ? and status = ? order by expires_on nulls last, id',
        [$sku->id, 'active']
    );

    expect(array_column($order, 'id'))->toBe([
        $expiresSoon->id,
        $expiresLater->id,
        $noExpiry->id,
    ]);
});

// -----------------------------------------------------------------
// deferred batch_id FKs, now wired
// -----------------------------------------------------------------

it('wires stock_levels.batch_id to a real batches row', function () {
    $batch = Batch::factory()->create();
    $level = StockLevel::factory()->forBatch($batch)->create();

    $reloaded = StockLevel::identity($level->sku_id, $level->location_id, $batch->id)->first();

    expect($reloaded->batch->id)->toBe($batch->id);
});

it('rejects a stock_levels row referencing a nonexistent batch_id', function () {
    StockLevel::factory()->create(['batch_id' => 999999999]);
})->throws(QueryException::class);

it('wires stock_allocations.batch_id to a real batches row via Batch::stockAllocations()', function () {
    $batch = Batch::factory()->create();
    $allocation = StockAllocation::factory()->forBatch($batch)->create();

    expect($batch->stockAllocations()->first()->id)->toBe($allocation->id);
});

it('rejects a stock_allocations row referencing a nonexistent batch_id', function () {
    StockAllocation::factory()->create(['batch_id' => 999999999]);
})->throws(QueryException::class);

it('confirms both batch_id column comments were cleared once the FKs were wired', function () {
    $stockLevelsComment = DB::selectOne(
        "select col_description('stock_levels'::regclass, ordinal_position) as c from information_schema.columns where table_name = 'stock_levels' and column_name = 'batch_id'"
    )->c;
    $stockAllocationsComment = DB::selectOne(
        "select col_description('stock_allocations'::regclass, ordinal_position) as c from information_schema.columns where table_name = 'stock_allocations' and column_name = 'batch_id'"
    )->c;

    expect($stockLevelsComment)->toBeNull()
        ->and($stockAllocationsComment)->toBeNull();
});
