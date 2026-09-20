<?php

use App\Models\OrderLine;
use App\Models\Sku;
use App\Models\SkuCost;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes landed_cost_e4 as a stored generated column', function () {
    $cost = SkuCost::factory()->create([
        'fob_e4' => 8000,
        'freight_e4' => 1200,
        'duty_e4' => 500,
        'other_e4' => 300,
    ]);

    expect($cost->fresh()->landed_cost_e4)->toBe(10000);
});

it('recomputes landed_cost_e4 when a component changes', function () {
    $cost = SkuCost::factory()->create(['fob_e4' => 1000, 'freight_e4' => 0, 'duty_e4' => 0, 'other_e4' => 0]);

    $cost->update(['freight_e4' => 250]);

    expect($cost->fresh()->landed_cost_e4)->toBe(1250);
});

it('rejects a source outside the CHECK list', function () {
    SkuCost::factory()->create(['source' => 'supplier_portal']);
})->throws(QueryException::class);

it('rejects a non-GBP currency without an fx_rate_e4', function () {
    SkuCost::factory()->create(['currency' => 'USD', 'fx_rate_e4' => null]);
})->throws(QueryException::class);

it('accepts a non-GBP currency with an fx_rate_e4', function () {
    $cost = SkuCost::factory()->inCurrency('USD', 7853)->create();

    expect($cost->currency)->toBe('USD')
        ->and($cost->fx_rate_e4)->toBe(7853);
});

it('defaults GBP costs to no fx_rate_e4 required', function () {
    $cost = SkuCost::factory()->create();

    expect($cost->currency)->toBe('GBP')
        ->and($cost->fx_rate_e4)->toBeNull();
});

it('cascades delete when the owning sku is deleted', function () {
    $sku = Sku::factory()->create();
    $cost = SkuCost::factory()->for($sku)->create();

    $sku->forceDelete();

    expect(SkuCost::find($cost->id))->toBeNull();
});

it('resolves current cost as the newest valid_from via Sku::costs()', function () {
    $sku = Sku::factory()->create();
    SkuCost::factory()->for($sku)->asOf(now()->subMonth())->create(['fob_e4' => 1000]);
    $current = SkuCost::factory()->for($sku)->asOf(now())->create(['fob_e4' => 1200]);

    expect($sku->costs()->first()->id)->toBe($current->id);
});

it('wires order_lines.sku_cost_id to a real sku_costs row', function () {
    $cost = SkuCost::factory()->create();
    $line = OrderLine::factory()->withSkuCost($cost)->create();

    expect($line->fresh()->sku_cost_id)->toBe($cost->id)
        ->and($line->skuCost->id)->toBe($cost->id)
        ->and($line->unit_cost_e4)->toBe($cost->fresh()->landed_cost_e4);
});

it('rejects an order line referencing a nonexistent sku_cost_id', function () {
    OrderLine::factory()->create(['sku_cost_id' => 999999999]);
})->throws(QueryException::class);
