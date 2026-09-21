<?php

use App\Domain\Ordering\CartService;
use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Pack;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds a new line for a fresh (cart, sku, pack) combination', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);

    $line = (new CartService)->addLine($cart, $pack, 5);

    expect($line->cart_id)->toBe($cart->id)
        ->and($line->sku_id)->toBe($sku->id)
        ->and($line->pack_id)->toBe($pack->id)
        ->and($line->pack_qty)->toBe(5)
        ->and($line->base_qty)->toBe(5)
        ->and(CartLine::query()->count())->toBe(1);
});

it('merges quantity onto the existing line rather than duplicating it', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);

    $service = new CartService;
    $first = $service->addLine($cart, $pack, 5);
    $second = $service->addLine($cart, $pack, 3);

    expect($second->id)->toBe($first->id)
        ->and($second->pack_qty)->toBe(8)
        ->and($second->base_qty)->toBe(8)
        ->and(CartLine::query()->count())->toBe(1);
});

it('updates quantity in place, recomputing base_qty from the stored pack_base_units', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create(['base_units' => 12]);

    $line = (new CartService)->addLine($cart, $pack, 2); // base_qty = 24

    $updated = (new CartService)->updateQuantity($line, 4);

    expect($updated->pack_qty)->toBe(4)
        ->and($updated->base_qty)->toBe(48);
});

it('rejects a non-positive quantity on add and on update', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create();
    $service = new CartService;

    expect(fn () => $service->addLine($cart, $pack, 0))->toThrow(InvalidArgumentException::class);

    $line = $service->addLine($cart, $pack, 1);
    expect(fn () => $service->updateQuantity($line, -1))->toThrow(InvalidArgumentException::class);
});

it('changes pack as a line UPDATE, not a delete-and-recreate (05.1 §8.3)', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $each = Pack::factory()->for($sku)->create(['base_units' => 1, 'code' => 'EACH']);
    $outer = Pack::factory()->for($sku)->outer(12)->create();

    $line = (new CartService)->addLine($cart, $each, 24);
    $lineId = $line->id;

    $changed = (new CartService)->changePack($line, $outer, 2);

    expect($changed->id)->toBe($lineId) // same row, updated in place
        ->and($changed->pack_id)->toBe($outer->id)
        ->and($changed->pack_base_units)->toBe(12)
        ->and($changed->pack_qty)->toBe(2)
        ->and($changed->base_qty)->toBe(24)
        ->and(CartLine::query()->count())->toBe(1);
});

it('changePack merges onto an existing line for the target pack instead of colliding on the unique constraint', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $each = Pack::factory()->for($sku)->create(['base_units' => 1, 'code' => 'EACH']);
    $outer = Pack::factory()->for($sku)->outer(12)->create();

    $service = new CartService;
    $eachLine = $service->addLine($cart, $each, 5); // base_qty 5
    $outerLine = $service->addLine($cart, $outer, 1); // base_qty 12
    $outerLineId = $outerLine->id;

    $merged = $service->changePack($eachLine, $outer, 3); // 3 outers = 36 base

    expect($merged->id)->toBe($outerLineId)
        ->and($merged->pack_qty)->toBe(4) // 1 + 3
        ->and($merged->base_qty)->toBe(48) // 12 + 36
        ->and(CartLine::query()->count())->toBe(1)
        ->and(CartLine::query()->find($eachLine->id))->toBeNull();
});

it('rejects a pack change to a pack belonging to a different sku', function () {
    $cart = Cart::factory()->create();
    $skuA = Sku::factory()->create();
    $skuB = Sku::factory()->create();
    $packA = Pack::factory()->for($skuA)->create();
    $packB = Pack::factory()->for($skuB)->create();

    $line = (new CartService)->addLine($cart, $packA, 1);

    expect(fn () => (new CartService)->changePack($line, $packB))->toThrow(InvalidArgumentException::class);
});

it('removes a line', function () {
    $cart = Cart::factory()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create();

    $line = (new CartService)->addLine($cart, $pack, 1);
    (new CartService)->removeLine($line);

    expect(CartLine::query()->count())->toBe(0);
});
