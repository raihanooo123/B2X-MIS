<?php

use App\Models\Batch;
use App\Models\Location;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\SystemConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------
// locations
// -----------------------------------------------------------------

it('rejects a location_type outside the CHECK list', function () {
    Location::factory()->create(['location_type' => 'depot']);
})->throws(QueryException::class);

it('rejects a duplicate location code', function () {
    Location::factory()->create(['code' => 'MAIN']);
    Location::factory()->create(['code' => 'MAIN']);
})->throws(QueryException::class);

it('allows only one default location system-wide', function () {
    Location::factory()->default()->create();
    Location::factory()->default()->create();
})->throws(QueryException::class);

// -----------------------------------------------------------------
// stock_levels
// -----------------------------------------------------------------

it('computes available_base_qty as a stored generated column', function () {
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    StockLevel::factory()->for($sku)->for($location)->create([
        'on_hand_base_qty' => 100,
        'allocated_base_qty' => 35,
    ]);

    $level = StockLevel::identity($sku->id, $location->id)->first();

    expect($level->available_base_qty)->toBe(65);
});

it('rejects a negative on_hand_base_qty', function () {
    StockLevel::factory()->create(['on_hand_base_qty' => -1]);
})->throws(QueryException::class);

it('rejects a negative allocated_base_qty', function () {
    StockLevel::factory()->create(['allocated_base_qty' => -1]);
})->throws(QueryException::class);

it('collapses two untracked (NULL batch_id) rows for the same sku+location via NULLS NOT DISTINCT', function () {
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    StockLevel::factory()->for($sku)->for($location)->create();

    StockLevel::factory()->for($sku)->for($location)->create();
})->throws(QueryException::class);

it('allows two rows for the same sku+location when batch_id differs', function () {
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $batchA = Batch::factory()->create();
    $batchB = Batch::factory()->create();
    StockLevel::factory()->for($sku)->for($location)->forBatch($batchA)->create();
    $second = StockLevel::factory()->for($sku)->for($location)->forBatch($batchB)->create();

    expect($second->exists)->toBeTrue();
});

it('reloads the correct row via reload(), not an arbitrary one', function () {
    $skuA = Sku::factory()->create();
    $skuB = Sku::factory()->create();
    $location = Location::factory()->create();
    $levelA = StockLevel::factory()->for($skuA)->for($location)->create(['on_hand_base_qty' => 10]);
    StockLevel::factory()->for($skuB)->for($location)->create(['on_hand_base_qty' => 999]);

    $reloaded = $levelA->reload();

    expect($reloaded->sku_id)->toBe($levelA->sku_id)
        ->and($reloaded->on_hand_base_qty)->toBe(10);
});

it('throws from fresh() and refresh() instead of silently returning the wrong row', function () {
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    $level = StockLevel::factory()->for($sku)->for($location)->create();

    $level->fresh();
})->throws(LogicException::class);

it('cascades delete of stock_levels when the owning sku is deleted', function () {
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    StockLevel::factory()->for($sku)->for($location)->create();

    $sku->forceDelete();

    expect(StockLevel::identity($sku->id, $location->id)->exists())->toBeFalse();
});

// -----------------------------------------------------------------
// stock_movements
// -----------------------------------------------------------------

it('rejects a movement_type outside the CHECK list', function () {
    StockMovement::factory()->create(['movement_type' => 'teleport']);
})->throws(QueryException::class);

it('requires a reason_code for adjustment/stocktake/write_off movements', function () {
    StockMovement::factory()->create(['movement_type' => 'adjustment', 'reason_code' => null]);
})->throws(QueryException::class);

it('accepts an adjustment movement with a reason_code', function () {
    $movement = StockMovement::factory()->adjustment('cycle_count_variance')->create();

    expect($movement->reason_code)->toBe('cycle_count_variance');
});

it('is append-only: update() throws instead of issuing an UPDATE', function () {
    $movement = StockMovement::factory()->create();

    $movement->update(['base_qty' => 999]);
})->throws(LogicException::class);

it('is append-only: delete() throws instead of issuing a DELETE', function () {
    $movement = StockMovement::factory()->create();

    $movement->delete();
})->throws(LogicException::class);

it('routes rows to the correct yearly partition by occurred_at', function () {
    $in2026 = StockMovement::factory()->create(['occurred_at' => '2026-06-01 12:00:00']);
    $in2027 = StockMovement::factory()->create(['occurred_at' => '2027-06-01 12:00:00']);
    $inDefault = StockMovement::factory()->create(['occurred_at' => '2030-06-01 12:00:00']);

    $partitionOf = fn (int $id) => DB::selectOne('select tableoid::regclass::text as t from stock_movements where id = ?', [$id])->t;

    expect($partitionOf($in2026->id))->toBe('stock_movements_2026')
        ->and($partitionOf($in2027->id))->toBe('stock_movements_2027')
        ->and($partitionOf($inDefault->id))->toBe('stock_movements_default');
});

// -----------------------------------------------------------------
// system_configurations.location_id FK
// -----------------------------------------------------------------

it('wires system_configurations.location_id to a real locations row', function () {
    $location = Location::factory()->create();
    $config = SystemConfiguration::factory()->create([
        'scope' => 'location',
        'location_id' => $location->id,
        'config_key' => 'min_order_value_minor',
    ]);

    expect($config->location->id)->toBe($location->id)
        ->and($location->systemConfigurations()->first()->id)->toBe($config->id);
});

it('rejects a system_configurations row referencing a nonexistent location_id', function () {
    SystemConfiguration::factory()->create([
        'scope' => 'location',
        'location_id' => 999999999,
        'config_key' => 'min_order_value_minor',
    ]);
})->throws(QueryException::class);

// -----------------------------------------------------------------
// relationships
// -----------------------------------------------------------------

it('resolves Location -> stockLevels, stockMovements, and Sku -> stockLevels, stockMovements', function () {
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    StockLevel::factory()->for($sku)->for($location)->create();
    // StockMovement has no belongsTo() relations by design (no DB-level
    // FK, §7.4 point 3) — Location::stockMovements() and
    // Sku::stockMovements() are the ORM-side convenience, so the FK-style
    // columns are set as plain attributes here, not via for().
    StockMovement::factory()->create(['sku_id' => $sku->id, 'location_id' => $location->id]);

    expect($location->stockLevels()->count())->toBe(1)
        ->and($location->stockMovements()->count())->toBe(1)
        ->and($sku->stockLevels()->count())->toBe(1)
        ->and($sku->stockMovements()->count())->toBe(1);
});
