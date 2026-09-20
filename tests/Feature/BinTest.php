<?php

use App\Models\Bin;
use App\Models\Location;
use App\Models\StockAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects a duplicate (location_id, code) pair', function () {
    $location = Location::factory()->create();
    Bin::factory()->for($location)->create(['code' => 'A1-01']);

    Bin::factory()->for($location)->create(['code' => 'A1-01']);
})->throws(QueryException::class);

it('allows the same bin code across different locations', function () {
    Bin::factory()->create(['code' => 'A1-01']);
    $second = Bin::factory()->create(['code' => 'A1-01']);

    expect($second->exists)->toBeTrue();
});

it('rejects a bin referencing a nonexistent location_id', function () {
    Bin::factory()->create(['location_id' => 999999999]);
})->throws(QueryException::class);

it('allows walk_sequence to be null and populated independently', function () {
    $unordered = Bin::factory()->create();
    $ordered = Bin::factory()->onWalkRoute(5)->create();

    expect($unordered->walk_sequence)->toBeNull()
        ->and($ordered->walk_sequence)->toBe(5);
});

it('resolves Location -> bins and Bin -> location', function () {
    $location = Location::factory()->create();
    Bin::factory()->for($location)->count(3)->create();

    $bin = $location->bins()->first();

    expect($location->bins()->count())->toBe(3)
        ->and($bin->location->id)->toBe($location->id);
});

it('wires stock_allocations.suggested_bin_id to a real bins row', function () {
    $bin = Bin::factory()->create();
    $allocation = StockAllocation::factory()->forBin($bin)->create();

    expect($allocation->suggestedBin->id)->toBe($bin->id)
        ->and($bin->suggestedAllocations()->first()->id)->toBe($allocation->id);
});

it('rejects a stock_allocations row referencing a nonexistent suggested_bin_id', function () {
    StockAllocation::factory()->create(['suggested_bin_id' => 999999999]);
})->throws(QueryException::class);

it('allows suggested_bin_id to remain null — the bin is advisory only', function () {
    $allocation = StockAllocation::factory()->create();

    expect($allocation->suggested_bin_id)->toBeNull();
});

it('confirms the pending column comment on suggested_bin_id was cleared', function () {
    $comment = DB::selectOne(
        "select col_description('stock_allocations'::regclass, ordinal_position) as c from information_schema.columns where table_name = 'stock_allocations' and column_name = 'suggested_bin_id'"
    )->c;

    expect($comment)->toBeNull();
});
