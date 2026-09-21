<?php

use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutRequest;
use App\Domain\Ordering\CheckoutService;
use App\Domain\Pricing\BulkPriceResolver;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Doc 05.1 §11: order-pad (bulk-resolve) totals must equal checkout's
 * exactly, for the same basket — otherwise a buyer sees one figure on
 * the pad and a different one at payment. This is exactly the drift
 * that motivated wiring TaxRateResolver into BulkPriceResolver: before
 * that, bulk-resolve always returned taxRateBp = 0 while checkout
 * charged real VAT, so the two totals below would have disagreed by
 * the tax amount on every order.
 */
it('bulk-resolve and checkout produce identical gross totals for the same basket', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $location = Location::factory()->default()->create();

    $company = Company::factory()->create(['credit_limit_minor' => 10000000]);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $base = PriceList::factory()->create(['scope' => 'base']);

    $skuA = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $packA = Pack::factory()->for($skuA)->create(['base_units' => 1]);
    PriceListItem::factory()->for($base, 'priceList')->for($skuA)->create(['min_base_qty' => 1, 'unit_price_e4' => 123400]);
    StockLevel::factory()->for($skuA)->for($location)->create(['on_hand_base_qty' => 100]);

    $skuB = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $packB = Pack::factory()->for($skuB)->create(['base_units' => 1]);
    PriceListItem::factory()->for($base, 'priceList')->for($skuB)->create(['min_base_qty' => 1, 'unit_price_e4' => 67800]);
    StockLevel::factory()->for($skuB)->for($location)->create(['on_hand_base_qty' => 100]);

    $qtyA = 5;
    $qtyB = 3;

    // Order pad: bulk-resolve each line at its own quantity (03 §8's
    // per-row usage), reproduce exactly the client-side sum §5.1 §11
    // requires the buyer to see before checkout.
    $bulkGrossMinor = 0;
    foreach ([[$skuA, $qtyA], [$skuB, $qtyB]] as [$sku, $qty]) {
        $resolved = (new BulkPriceResolver)->resolveMany([$sku->id], $company->id, $qty, countryCode: 'GB')->resolved[$sku->id];
        $lineNetMinor = Money::roundHalfUpDiv($resolved->unitPriceE4 * $qty, 100);
        $lineTaxMinor = Money::roundHalfUpDiv($lineNetMinor * $resolved->taxRateBp, 10000);
        $bulkGrossMinor += $lineNetMinor + $lineTaxMinor;
    }

    // Checkout/preview: the server's own re-resolution for the same basket.
    $preview = (new OrderPricingPipeline)->price(
        [
            new OrderLineRequest(skuId: $skuA->id, baseQty: $qtyA),
            new OrderLineRequest(skuId: $skuB->id, baseQty: $qtyB),
        ],
        companyId: $company->id,
        tierId: null,
        deliveryCountryCode: 'GB',
    );

    expect($bulkGrossMinor)->toBe($preview->totalGrossMinor);

    // Checkout itself: the persisted order must match too, not just the preview.
    $cart = Cart::factory()->create(['company_id' => $company->id]);
    $cartService = new CartService;
    $cartService->addLine($cart, $packA, $qtyA);
    $cartService->addLine($cart, $packB, $qtyB);
    $cart = $cart->fresh();

    $order = (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id,
        companyId: $company->id,
        userId: null,
        paymentMethod: 'on_account',
        deliveryCountryCode: 'GB',
        expectedTotalGrossMinor: $preview->totalGrossMinor,
    ));

    expect($order->total_gross_minor)->toBe($bulkGrossMinor);
});
