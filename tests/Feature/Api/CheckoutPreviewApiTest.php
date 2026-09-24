<?php

use App\Domain\Ordering\CheckoutPreviewService;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Http\Support\CartContext;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditHold;
use App\Models\Location;
use App\Models\Order;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->location = Location::factory()->default()->create();
    // One active base list per currency (price_lists_no_base_overlap).
    $this->baseList = PriceList::factory()->create(['scope' => 'base']);
});

/**
 * An active, GB-taxed (20%), base-priced SKU with stock at the default
 * location and a cost row — the cost exists so the "no cost in the
 * response" assertion is meaningful, not vacuous.
 *
 * @return array{sku: Sku, pack: Pack}
 */
function previewSku(int $unitPriceE4 = 10000, int $onHand = 1000, array $skuAttributes = []): array
{
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);

    $sku = Sku::factory()->create(['tax_class_id' => $taxClass->id] + $skuAttributes);
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);
    PriceListItem::factory()->for(test()->baseList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $unitPriceE4]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);
    SkuCost::factory()->for($sku)->create();

    return ['sku' => $sku, 'pack' => $pack];
}

/**
 * @return array{user: User, company: Company}
 */
function previewBuyer(): array
{
    $company = Company::factory()->create(['credit_limit_minor' => 500000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    Address::factory()->default()->delivery()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    return ['user' => $user, 'company' => $company];
}

function previewAdd(User $user, Sku $sku, int $packQty): void
{
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => $packQty])->assertSuccessful();
}

it('returns totals identical to OrderPricingPipeline for the same lines, with no blockers', function () {
    ['sku' => $a] = previewSku(unitPriceE4: 9212);
    ['sku' => $b] = previewSku(unitPriceE4: 12345);
    ['user' => $user, 'company' => $company] = previewBuyer();
    previewAdd($user, $a, 144);
    previewAdd($user, $b, 7);

    $expected = (new OrderPricingPipeline)->price(
        [new OrderLineRequest($a->id, 144), new OrderLineRequest($b->id, 7)],
        $company->id,
        null,
        'GB',
    );

    $this->actingAs($user)->postJson('/api/v1/checkout/preview', [])
        ->assertOk()
        ->assertJsonPath('subtotal_net_minor', $expected->subtotalNetMinor)
        ->assertJsonPath('tax_minor', $expected->taxMinor)
        ->assertJsonPath('total_gross_minor', $expected->totalGrossMinor)
        ->assertJsonPath('amount_due_minor', $expected->totalGrossMinor)
        ->assertJsonPath('credit.available_minor', 500000)
        ->assertJsonPath('credit.sufficient', true)
        ->assertJsonPath('lines.0.unit_price_net_e4', 9212)
        ->assertJsonPath('blockers', []);
});

it('writes nothing: no allocation, no credit hold, no order, no stock movement, no cart', function () {
    ['sku' => $sku] = previewSku(onHand: 50);
    ['user' => $user, 'company' => $company] = previewBuyer();
    previewAdd($user, $sku, 10);

    $counts = fn () => [
        Order::query()->count(),
        StockAllocation::query()->count(),
        StockMovement::query()->count(),
        CreditHold::query()->count(),
        Cart::query()->count(),
        CartLine::query()->count(),
        StockLevel::query()->sum('allocated_base_qty'),
        Company::query()->whereKey($company->id)->value('credit_held_minor'),
        CartLine::query()->max('updated_at'),
    ];
    $before = $counts();

    $this->actingAs($user)->postJson('/api/v1/checkout/preview', [])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/checkout/preview', [])->assertOk();

    expect($counts())->toBe($before);
});

it('creates neither a cart nor a guest token for a guest previewing with no cart', function () {
    $this->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])
        ->assertOk()
        ->assertJsonPath('blockers.0.code', 'cart_empty');

    expect(Cart::query()->count())->toBe(0)
        ->and(session()->has(CartContext::SESSION_KEY))->toBeFalse();
});

it('reports MOQ, increment, stock and minimum-order failures as blockers, not errors', function () {
    ['sku' => $moq] = previewSku(skuAttributes: ['moq_base_qty' => 12]);
    ['sku' => $inc] = previewSku(skuAttributes: ['order_increment_base_qty' => 6]);
    ['sku' => $short] = previewSku(onHand: 3);
    ['user' => $user, 'company' => $company] = previewBuyer();
    SystemConfiguration::factory()->create([
        'config_key' => CheckoutPreviewService::MINIMUM_ORDER_CONFIG_KEY,
        'value_type' => 'money_minor',
        'value_int' => 100000000,
    ]);

    previewAdd($user, $moq, 5);
    previewAdd($user, $inc, 7);
    previewAdd($user, $short, 4);

    $response = $this->actingAs($user)->postJson('/api/v1/checkout/preview', [])->assertOk();

    $blockers = collect($response->json('blockers'))->keyBy('code');

    expect($blockers->keys()->sort()->values()->all())
        ->toBe(['below_minimum_order', 'insufficient_stock', 'moq_not_met', 'order_increment_violation'])
        ->and($blockers['moq_not_met']['field'])->toBe('lines.0.base_qty')
        ->and($blockers['moq_not_met']['meta']['moq_base_qty'])->toBe(12)
        ->and($blockers['order_increment_violation']['meta']['next_valid_base_qty'])->toBe(12)
        ->and($blockers['insufficient_stock']['meta']['requested_base_qty'])->toBe(4)
        ->and($blockers['insufficient_stock']['meta']['available_base_qty'])->toBe(3)
        ->and($blockers['below_minimum_order']['meta']['minimum_net_minor'])->toBe(100000000);

    // Blocked lines are still priced — the buyer sees the figures while fixing them.
    expect($response->json('subtotal_net_minor'))->toBeGreaterThan(0);
});

it('blocks a line whose SKU was deactivated while in the cart, and excludes it from totals', function () {
    ['sku' => $live] = previewSku(unitPriceE4: 10000);
    ['sku' => $gone] = previewSku(unitPriceE4: 50000);
    ['user' => $user] = previewBuyer();
    previewAdd($user, $live, 1);
    previewAdd($user, $gone, 1);
    $gone->update(['status' => 'discontinued']);

    $this->actingAs($user)->postJson('/api/v1/checkout/preview', [])
        ->assertOk()
        ->assertJsonPath('blockers.0.code', 'not_purchasable')
        ->assertJsonPath('blockers.0.field', 'lines.1')
        ->assertJsonPath('subtotal_net_minor', 100)
        ->assertJsonPath('lines.1.priced', false);
});

it('never returns a cost or margin field', function () {
    ['sku' => $sku] = previewSku();
    ['user' => $user] = previewBuyer();
    previewAdd($user, $sku, 3);

    $body = $this->actingAs($user)->postJson('/api/v1/checkout/preview', [])->assertOk()->getContent();

    expect($body)->not->toContain('cost')
        ->and($body)->not->toContain('margin');
});

it('rejects delivery_address_id, which cannot be addressed by ULID yet', function () {
    ['user' => $user] = previewBuyer();

    $this->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_address_id' => '01J8ZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.field', 'delivery_address_id');
});

it('needs a delivery country rather than assuming one', function () {
    ['sku' => $sku] = previewSku();
    $this->withSession([CartContext::SESSION_KEY => str_repeat('p', 64)])
        ->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 1])
        ->assertCreated();

    $this->withSession([CartContext::SESSION_KEY => str_repeat('p', 64)])
        ->postJson('/api/v1/checkout/preview', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'delivery_country_required');

    $this->withSession([CartContext::SESSION_KEY => str_repeat('p', 64)])
        ->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])
        ->assertOk()
        ->assertJsonPath('credit', null)
        // Priced, and nothing wrong with the cart — but a guest must sign
        // in before checking out (05.13 §4.1).
        ->assertJsonPath('blockers', [[
            'field' => null,
            'code' => 'sign_in_required',
            'message' => 'Sign in or create an account to check out.',
            'meta' => [],
        ]]);
});
