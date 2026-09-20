<?php

use App\Models\Batch;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Sku;
use App\Models\StockAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a status outside the CHECK list', function () {
    StockAllocation::factory()->create(['status' => 'reserved']);
})->throws(QueryException::class);

it('rejects a base_qty of zero or less', function () {
    StockAllocation::factory()->create(['base_qty' => 0]);
})->throws(QueryException::class);

it('prevents double-allocating the same line/location for an untracked (NULL batch_id) SKU', function () {
    $line = OrderLine::factory()->create();
    $location = Location::factory()->create();
    StockAllocation::factory()->for($line, 'orderLine')->for($location)->create();

    StockAllocation::factory()->for($line, 'orderLine')->for($location)->create();
})->throws(QueryException::class);

it('allows one line to be satisfied from several batches at the same location', function () {
    $line = OrderLine::factory()->create();
    $location = Location::factory()->create();
    $batchA = Batch::factory()->create();
    $batchB = Batch::factory()->create();

    StockAllocation::factory()->for($line, 'orderLine')->for($location)->forBatch($batchA)->create(['base_qty' => 300]);
    $second = StockAllocation::factory()->for($line, 'orderLine')->for($location)->forBatch($batchB)->create(['base_qty' => 132]);

    expect($second->exists)->toBeTrue();
});

it('rejects an allocation referencing a nonexistent order_line_id', function () {
    StockAllocation::factory()->create(['order_line_id' => 999999999]);
})->throws(QueryException::class);

it('rejects an allocation referencing a nonexistent batch_id', function () {
    StockAllocation::factory()->create(['batch_id' => 999999999]);
})->throws(QueryException::class);

it('wires stock_allocations.batch_id to a real batches row', function () {
    $batch = Batch::factory()->create();
    $allocation = StockAllocation::factory()->forBatch($batch)->create();

    expect($allocation->batch->id)->toBe($batch->id)
        ->and($batch->stockAllocations()->first()->id)->toBe($allocation->id);
});

it('resolves OrderLine -> allocations, Sku -> stockAllocations, Location -> stockAllocations', function () {
    $line = OrderLine::factory()->create();
    $sku = Sku::factory()->create();
    $location = Location::factory()->create();
    StockAllocation::factory()->for($line, 'orderLine')->for($sku)->for($location)->create();

    expect($line->allocations()->count())->toBe(1)
        ->and($sku->stockAllocations()->count())->toBe(1)
        ->and($location->stockAllocations()->count())->toBe(1);
});
