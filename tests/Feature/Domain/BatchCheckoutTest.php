<?php

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\NoEligibleBatchException;
use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutPreviewService;
use App\Domain\Ordering\CheckoutRequest;
use App\Domain\Ordering\CheckoutService;
use App\Domain\Ordering\Exceptions\BatchTrackedCheckoutNotSupportedException;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Models\Batch;
use App\Models\Cart;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $this->location = Location::factory()->default()->create();
});

/** @return array{sku: Sku, pack: Pack} */
function batchCheckoutItem(array $attributes = []): array
{
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $sku = Sku::factory()->batchTracked()->create(array_merge(['tax_class_id' => $taxClass->id], $attributes));
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);
    $base = PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($base, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 10000]);

    return ['sku' => $sku, 'pack' => $pack];
}

function batchCheckoutStock(Sku $sku, int $qty, int $daysUntilExpiry, string $status = 'active'): Batch
{
    $batch = Batch::factory()->for($sku)->create([
        'expires_on' => now()->addDays($daysUntilExpiry)->toDateString(),
        'status' => $status,
    ]);
    StockLevel::factory()->for($sku)->for(test()->location)->forBatch($batch)->create([
        'on_hand_base_qty' => $qty,
        'allocated_base_qty' => 0,
    ]);

    return $batch;
}

function batchCheckoutCart(Pack $pack, int $qty): Cart
{
    $cart = Cart::factory()->create();
    (new CartService)->addLine($cart, $pack, $qty);

    return $cart;
}

function batchCheckout(Cart $cart, Sku $sku, int $qty): Order
{
    $pricing = (new OrderPricingPipeline)->price([new OrderLineRequest($sku->id, $qty)], null, null, 'GB');

    return (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: null,
        userId: null,
        paymentMethod: 'card',
        expectedTotalGrossMinor: $pricing->totalGrossMinor,
        deliveryCountryCode: 'GB',
        guestEmail: 'guest@example.com',
    ));
}

it('previews and allocates a batch-only line across eligible FEFO batches', function () {
    ['sku' => $sku, 'pack' => $pack] = batchCheckoutItem();
    $later = batchCheckoutStock($sku, 10, 120);
    $earlier = batchCheckoutStock($sku, 3, 60);
    $cart = batchCheckoutCart($pack, 8);

    $preview = (new CheckoutPreviewService)->preview($cart, null, 'GB');
    expect($preview->blockers)->toBe([]);

    $order = batchCheckout($cart, $sku, 8);
    $allocations = StockAllocation::query()->where('order_line_id', $order->lines->first()->id)->pluck('base_qty', 'batch_id');

    expect($allocations->all())->toBe([$earlier->id => 3, $later->id => 5])
        ->and(StockLevel::identity($sku->id, test()->location->id, $earlier->id)->first()->allocated_base_qty)->toBe(3)
        ->and(StockLevel::identity($sku->id, test()->location->id, $later->id)->first()->allocated_base_qty)->toBe(5)
        ->and($order->lines->first()->allocated_base_qty)->toBe(8);
});

it('excludes quarantined and short-life batches and rolls back a short checkout', function () {
    ['sku' => $sku, 'pack' => $pack] = batchCheckoutItem();
    batchCheckoutStock($sku, 10, 90, 'quarantined');
    batchCheckoutStock($sku, 10, 5);
    $eligible = batchCheckoutStock($sku, 2, 90);
    $cart = batchCheckoutCart($pack, 3);

    $preview = (new CheckoutPreviewService)->preview($cart, null, 'GB');
    expect(collect($preview->blockers)->contains(fn ($blocker) => $blocker->code === 'insufficient_stock'))->toBeTrue();

    expect(fn () => batchCheckout($cart, $sku, 3))->toThrow(InsufficientStockException::class);
    expect(Order::query()->count())->toBe(0)
        ->and(StockAllocation::query()->count())->toBe(0)
        ->and(StockLevel::identity($sku->id, test()->location->id, $eligible->id)->first()->allocated_base_qty)->toBe(0)
        ->and($cart->fresh()->lines()->count())->toBe(1);
});

it('reports no eligible batch separately when physical stock is all quarantined', function () {
    ['sku' => $sku, 'pack' => $pack] = batchCheckoutItem();
    batchCheckoutStock($sku, 5, 90, 'quarantined');
    $cart = batchCheckoutCart($pack, 1);

    $preview = (new CheckoutPreviewService)->preview($cart, null, 'GB');
    expect(collect($preview->blockers)->contains(fn ($blocker) => $blocker->code === 'no_eligible_batch'))->toBeTrue();
    expect(fn () => batchCheckout($cart, $sku, 1))->toThrow(NoEligibleBatchException::class);
    expect(Order::query()->count())->toBe(0);
});

it('uses LIFO when configured for a non-perishable batch SKU', function () {
    ['sku' => $sku, 'pack' => $pack] = batchCheckoutItem([
        'allocation_strategy' => 'lifo',
        'requires_expiry' => false,
        'min_remaining_shelf_life_days' => null,
    ]);
    $older = batchCheckoutStock($sku, 3, 90);
    $newer = batchCheckoutStock($sku, 3, 90);

    $order = batchCheckout(batchCheckoutCart($pack, 2), $sku, 2);
    expect(StockAllocation::query()->where('order_line_id', $order->lines->first()->id)->sole()->batch_id)->toBe($newer->id)
        ->and(StockLevel::identity($sku->id, test()->location->id, $older->id)->first()->allocated_base_qty)->toBe(0);
});

it('continues to block serial-tracked checkout until serial reservation is built', function () {
    ['sku' => $sku, 'pack' => $pack] = batchCheckoutItem([
        'tracking_mode' => 'batch_and_serial',
    ]);
    batchCheckoutStock($sku, 4, 90);
    $cart = batchCheckoutCart($pack, 1);

    $preview = (new CheckoutPreviewService)->preview($cart, null, 'GB');
    expect(collect($preview->blockers)->contains(fn ($blocker) => $blocker->code === 'batch_tracked_not_supported'))->toBeTrue();
    expect(fn () => batchCheckout($cart, $sku, 1))->toThrow(BatchTrackedCheckoutNotSupportedException::class);
});

it('blocks a batch SKU with no allocation strategy instead of failing inside allocation', function () {
    ['sku' => $sku, 'pack' => $pack] = batchCheckoutItem([
        'allocation_strategy' => 'none',
        'requires_expiry' => false,
        'min_remaining_shelf_life_days' => null,
    ]);
    batchCheckoutStock($sku, 4, 90);
    $cart = batchCheckoutCart($pack, 1);

    $preview = (new CheckoutPreviewService)->preview($cart, null, 'GB');
    expect(collect($preview->blockers)->pluck('code')->all())->toContain('batch_tracked_not_supported');
    expect(fn () => batchCheckout($cart, $sku, 1))->toThrow(BatchTrackedCheckoutNotSupportedException::class);
    expect(Order::query()->count())->toBe(0);
});
