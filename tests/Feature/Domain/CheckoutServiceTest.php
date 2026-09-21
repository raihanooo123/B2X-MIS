<?php

use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutRequest;
use App\Domain\Ordering\CheckoutService;
use App\Domain\Ordering\Exceptions\PriceChangedException;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Models\Cart;
use App\Models\Company;
use App\Models\CreditHold;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 02 §11.3: the checkout path takes order_number LAST, from a real
    // provisioned sequence row — never guessed into existence.
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $this->location = Location::factory()->default()->create();
});

/**
 * A fresh GB tax class with one real, currently-valid tax_rates row —
 * TaxRateResolver (03 §10) now resolves a genuine rate per SKU, so
 * every SKU checked out in this file needs one. No sharing needed:
 * `tax_rates_no_overlap` is scoped per tax_class_id, so distinct
 * classes never collide.
 */
function checkoutTaxClass(int $rateBp = 2000): int
{
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => $rateBp]);

    return $taxClass->id;
}

/**
 * One active base-scope SKU with stock on hand at the default location,
 * priced at $unitPriceE4 per base unit, in a pack of $packBaseUnits.
 * Only one active base-scope price list per currency can exist at a time
 * (`price_lists_no_base_overlap`, 02 §6.3) — a fresh list per call would
 * collide the second time this is called in one test, so callers share
 * one base list via $sharedBase, same convention as
 * OrderPricingPipelineTest's skuWithBasePrice().
 *
 * @return array{sku: Sku, pack: Pack}
 */
function checkoutSku(int $unitPriceE4 = 10000, int $onHand = 100, int $packBaseUnits = 1, ?PriceList &$sharedBase = null): array
{
    $sku = Sku::factory()->create([
        'is_stock_tracked' => true,
        'tracking_mode' => 'none',
        'tax_class_id' => checkoutTaxClass(),
    ]);
    $pack = Pack::factory()->for($sku)->create(['base_units' => $packBaseUnits]);
    $sharedBase ??= PriceList::factory()->create(['scope' => 'base']);
    PriceListItem::factory()->for($sharedBase, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $unitPriceE4]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);

    return ['sku' => $sku, 'pack' => $pack];
}

function cartWithLine(Pack $pack, int $packQty, ?int $companyId = null, ?int $userId = null): Cart
{
    $cart = Cart::factory()->create(['company_id' => $companyId, 'user_id' => $userId]);
    (new CartService)->addLine($cart, $pack, $packQty);

    return $cart->fresh();
}

it('checks out an on-account company order: order+lines snapshot, stock allocated, credit held', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 1000000, 'payment_terms' => 'net30']);
    $user = User::factory()->create();
    ['sku' => $sku, 'pack' => $pack] = checkoutSku(unitPriceE4: 10000, onHand: 50);
    $cart = cartWithLine($pack, 10, $company->id, $user->id); // base_qty 10 x £1.00 = 1000 minor

    $preview = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 10)],
        companyId: $company->id,
        tierId: null,
    );

    $order = (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: $user->id,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    ));

    expect($order->status)->toBe('confirmed')
        ->and($order->company_id)->toBe($company->id)
        ->and($order->order_number)->toStartWith('SO-')
        ->and($order->total_gross_minor)->toBe($preview->totalGrossMinor)
        ->and($order->payment_status)->toBe('on_account');

    $line = $order->lines->first();
    expect($line->sku_id)->toBe($sku->id)
        ->and($line->sku_code_snapshot)->toBe($sku->sku_code)
        ->and($line->base_qty)->toBe(10)
        ->and($line->unit_price_net_e4)->toBe(10000);

    expect(StockAllocation::query()->where('order_line_id', $line->id)->count())->toBe(1);
    expect(StockLevel::identity($sku->id, test()->location->id, null)->first()->allocated_base_qty)->toBe(10);

    $hold = CreditHold::query()->where('order_id', $order->id)->first();
    expect($hold)->not->toBeNull()
        ->and($hold->amount_minor)->toBe($preview->totalGrossMinor)
        ->and($hold->status)->toBe('held');

    expect($company->fresh()->credit_held_minor)->toBe($preview->totalGrossMinor);
    expect($cart->fresh()->lines()->count())->toBe(0);
});

it('checks out a public/guest order with no company: no credit check, no credit hold', function () {
    ['sku' => $sku, 'pack' => $pack] = checkoutSku(unitPriceE4: 5000, onHand: 20);
    $cart = cartWithLine($pack, 2);

    $preview = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 2)],
        companyId: null,
        tierId: null,
    );

    $order = (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: null,
        userId: null,
        paymentMethod: 'card',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    ));

    expect($order->company_id)->toBeNull()
        ->and($order->payment_status)->toBe('unpaid')
        ->and($order->status)->toBe('confirmed');

    expect(CreditHold::query()->count())->toBe(0);
    expect(StockAllocation::query()->where('order_line_id', $order->lines->first()->id)->count())->toBe(1);
});

it('rejects on_account checkout with no company', function () {
    ['sku' => $sku, 'pack' => $pack] = checkoutSku();
    $cart = cartWithLine($pack, 1);

    expect(fn () => (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: null,
        userId: null,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: 999999,
    )))->toThrow(InvalidArgumentException::class);
});

it('checkout/preview totals equal checkout totals exactly', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 1000000]);
    $base = null;
    ['sku' => $skuA, 'pack' => $packA] = checkoutSku(unitPriceE4: 12345, onHand: 100, sharedBase: $base);
    ['sku' => $skuB, 'pack' => $packB] = checkoutSku(unitPriceE4: 67890, onHand: 100, sharedBase: $base);
    $cart = Cart::factory()->create(['company_id' => $company->id]);
    $service = new CartService;
    $service->addLine($cart, $packA, 7);
    $service->addLine($cart, $packB, 3);
    $cart = $cart->fresh();

    $preview = (new OrderPricingPipeline)->price(
        [
            new OrderLineRequest(skuId: $skuA->id, baseQty: 7),
            new OrderLineRequest(skuId: $skuB->id, baseQty: 3),
        ],
        companyId: $company->id,
        tierId: null,
    );

    $order = (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: null,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    ));

    expect($order->subtotal_net_minor)->toBe($preview->subtotalNetMinor)
        ->and($order->tax_minor)->toBe($preview->taxMinor)
        ->and($order->total_gross_minor)->toBe($preview->totalGrossMinor);
});

it('rejects a stale expected total with PriceChangedException and commits nothing (06 §9.3)', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 1000000]);
    ['sku' => $sku, 'pack' => $pack] = checkoutSku(unitPriceE4: 10000, onHand: 50);
    $cart = cartWithLine($pack, 10, $company->id);

    expect(fn () => (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: null,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: 1, // stale — real total is £10.00 net
    )))->toThrow(PriceChangedException::class);

    expect(Order::query()->count())->toBe(0)
        ->and(CreditHold::query()->count())->toBe(0)
        ->and($cart->fresh()->lines()->count())->toBe(1); // untouched
});

it('throws InsufficientCreditException and commits nothing', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100]); // £0.01
    ['sku' => $sku, 'pack' => $pack] = checkoutSku(unitPriceE4: 10000, onHand: 50);
    $cart = cartWithLine($pack, 10, $company->id); // £10.00, way over the limit

    $preview = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 10)],
        companyId: $company->id,
        tierId: null,
    );

    expect(fn () => (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: null,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    )))->toThrow(InsufficientCreditException::class);

    expect(Order::query()->count())->toBe(0)
        ->and(StockAllocation::query()->count())->toBe(0)
        ->and(StockLevel::identity($sku->id, test()->location->id, null)->first()->allocated_base_qty)->toBe(0);
});

it('throws InsufficientStockException and commits nothing', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 1000000]);
    ['sku' => $sku, 'pack' => $pack] = checkoutSku(unitPriceE4: 10000, onHand: 5); // only 5 available
    $cart = cartWithLine($pack, 10, $company->id); // wants 10

    $preview = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 10)],
        companyId: $company->id,
        tierId: null,
    );

    expect(fn () => (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: null,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    )))->toThrow(InsufficientStockException::class);

    expect(Order::query()->count())->toBe(0)
        ->and(CreditHold::query()->count())->toBe(0)
        ->and($company->fresh()->credit_held_minor)->toBe(0);
});

it('locks companies before stock_levels, matching the 02 §11.1 / 05.2 §8.2 global order', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 1000000]);
    ['sku' => $sku, 'pack' => $pack] = checkoutSku(unitPriceE4: 10000, onHand: 50);
    $cart = cartWithLine($pack, 10, $company->id);

    $preview = (new OrderPricingPipeline)->price(
        [new OrderLineRequest(skuId: $sku->id, baseQty: 10)],
        companyId: $company->id,
        tierId: null,
    );

    DB::enableQueryLog();
    (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: null,
        paymentMethod: 'on_account',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    ));
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $lockQueries = array_values(array_filter(
        $log,
        fn (array $q) => str_contains($q['query'], 'for update')
            && (str_contains($q['query'], 'companies') || str_contains($q['query'], 'stock_levels'))
    ));

    expect($lockQueries)->not->toBeEmpty();
    expect($lockQueries[0]['query'])->toContain('companies');

    $firstStockLevelsIndex = null;
    foreach ($lockQueries as $index => $q) {
        if (str_contains($q['query'], 'stock_levels')) {
            $firstStockLevelsIndex = $index;
            break;
        }
    }

    expect($firstStockLevelsIndex)->not->toBeNull()
        ->and($firstStockLevelsIndex)->toBeGreaterThan(0);
});
