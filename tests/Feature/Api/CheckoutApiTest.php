<?php

use App\Models\Address;
use App\Models\B2bApplication;
use App\Models\Cart;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 06 §9.3 — POST /api/v1/checkout: idempotent (06 §6), refused while
 * preview reports blockers, 409 `price_changed` with both figures when
 * the confirmed total is stale, and the delivery address snapshotted
 * onto the order (02 §8.4). Plus the confirmation page's access rule.
 */
beforeEach(function () {
    $this->withoutVite();
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $this->location = Location::factory()->default()->create();
    $this->baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);

    $this->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    Pack::factory()->for($this->sku)->create(['base_units' => 1]);
    PriceListItem::factory()->for($this->baseList, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
});

function checkoutTradeBuyer(string $terms = 'net30', string $role = 'buyer'): User
{
    $company = Company::factory()->create(['payment_terms' => $terms, 'credit_limit_minor' => 10_000_000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);
    Address::factory()->default()->delivery()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    return $user;
}

function checkoutFill(User $user, int $packQty = 3): int
{
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => $packQty])->assertSuccessful();

    return (int) test()->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])->assertOk()->json('total_gross_minor');
}

/** @return array<string, mixed> */
function checkoutBody(int $expected, string $method = 'bacs', array $overrides = []): array
{
    return array_replace_recursive([
        'payment_method' => $method,
        'expected_total_gross_minor' => $expected,
        'customer_reference' => 'PO-4471',
        'delivery_address' => [
            'contact_name' => 'Asha Patel',
            'company_name' => 'Corner Shop Ltd',
            'phone' => '020 7946 0000',
            'line1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'e1 6an',
            'country_code' => 'gb',
        ],
    ], $overrides);
}

it('places the order, snapshots the delivery address and empties the cart', function () {
    $user = checkoutTradeBuyer();
    $total = checkoutFill($user);

    $response = $this->actingAs($user)
        ->withHeader('Idempotency-Key', '01J8XQK9V3AAAAAAAAAAAAAAAA')
        ->postJson('/api/v1/checkout', checkoutBody($total))
        ->assertCreated()
        ->assertJsonPath('data.total_gross_minor', $total);

    $order = Order::query()->sole();
    $address = OrderAddress::query()->sole();

    expect($response->json('data.order_number'))->toStartWith('SO-')
        ->and($response->json('data.confirmation_url'))->toBe(route('orders.confirmation', $order->public_id))
        ->and($order->status)->toBe('confirmed')
        ->and($order->payment_status)->toBe('unpaid')
        ->and($order->payment_method)->toBe('bacs')
        ->and($order->customer_reference)->toBe('PO-4471')
        ->and($order->total_gross_minor)->toBe($total)
        ->and($address->address_type)->toBe('delivery')
        ->and($address->postcode)->toBe('E1 6AN')
        ->and(trim($address->country_code))->toBe('GB')
        ->and(Cart::query()->sole()->lines()->count())->toBe(0);
});

it('answers a stale total with 409 price_changed and both figures, committing nothing', function () {
    $user = checkoutTradeBuyer();
    $total = checkoutFill($user);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', '01J8XQK9V3BBBBBBBBBBBBBBBB')
        ->postJson('/api/v1/checkout', checkoutBody($total - 1))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'price_changed')
        ->assertJsonPath('error.details.0.meta.expected_total_gross_minor', $total - 1)
        ->assertJsonPath('error.details.0.meta.actual_total_gross_minor', $total);

    expect(Order::query()->count())->toBe(0)
        ->and(Cart::query()->sole()->lines()->count())->toBe(1);
});

it('replays the stored response for a repeated key, placing one order', function () {
    $user = checkoutTradeBuyer();
    $total = checkoutFill($user);
    $key = '01J8XQK9V3CCCCCCCCCCCCCCCC';

    $first = $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', checkoutBody($total))->assertCreated();
    $second = $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', checkoutBody($total))->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and(Order::query()->count())->toBe(1);
});

it('refuses a reused key with a different body, and a missing key', function () {
    $user = checkoutTradeBuyer();
    $total = checkoutFill($user);
    $key = '01J8XQK9V3DDDDDDDDDDDDDDDD';

    $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', checkoutBody($total - 1))->assertStatus(409);
    $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', checkoutBody($total))
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reuse');

    $this->actingAs($user)->withHeader('Idempotency-Key', '')->postJson('/api/v1/checkout', checkoutBody($total))
        ->assertUnprocessable()->assertJsonPath('error.code', 'idempotency_key_required');

    expect(Order::query()->count())->toBe(0);
});

it('refuses with the preview blockers while any exist', function () {
    $user = User::factory()->unverified()->create();
    $total = checkoutFill($user);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', '01J8XQK9V3EEEEEEEEEEEEEEEE')
        ->postJson('/api/v1/checkout', checkoutBody($total))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'checkout_blocked')
        ->assertJsonPath('error.details.0.code', 'email_unverified');

    expect(Order::query()->count())->toBe(0);
});

it('reports applicants, viewers and guests as preview blockers', function () {
    $applicant = User::factory()->create();
    B2bApplication::factory()->create(['applicant_user_id' => $applicant->id, 'contact_email' => $applicant->email, 'status' => 'submitted']);
    checkoutFill($applicant);
    $this->actingAs($applicant)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])
        ->assertJsonPath('blockers.0.code', 'application_pending');

    $viewer = checkoutTradeBuyer(role: 'viewer');
    checkoutFill($viewer);
    $this->actingAs($viewer)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])
        ->assertJsonPath('blockers.0.code', 'not_permitted_to_order');
});

it('requires sign-in to place an order', function () {
    $this->withHeader('Idempotency-Key', '01J8XQK9V3FFFFFFFFFFFFFFFF')
        ->postJson('/api/v1/checkout', checkoutBody(100))
        ->assertUnauthorized();
});

it('offers on-account only to a company on credit terms', function () {
    $public = User::factory()->create();
    $total = checkoutFill($public);
    $this->actingAs($public)->withHeader('Idempotency-Key', '01J8XQK9V3GGGGGGGGGGGGGGGG')
        ->postJson('/api/v1/checkout', checkoutBody($total, 'on_account'))
        ->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_not_available');

    $prepay = checkoutTradeBuyer('prepay');
    $total = checkoutFill($prepay);
    $this->actingAs($prepay)->withHeader('Idempotency-Key', '01J8XQK9V3HHHHHHHHHHHHHHHH')
        ->postJson('/api/v1/checkout', checkoutBody($total, 'on_account'))
        ->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_not_available');

    $account = checkoutTradeBuyer('net30');
    $total = checkoutFill($account);
    $this->actingAs($account)->withHeader('Idempotency-Key', '01J8XQK9V3JJJJJJJJJJJJJJJJ')
        ->postJson('/api/v1/checkout', checkoutBody($total, 'on_account'))
        ->assertCreated();

    expect(Order::query()->sole()->payment_status)->toBe('on_account')
        ->and(Order::query()->sole()->payment_method)->toBe('on_account');
});

it('shows the confirmation to the buyer and their colleagues, and no one else', function () {
    $user = checkoutTradeBuyer();
    $total = checkoutFill($user);
    $this->actingAs($user)->withHeader('Idempotency-Key', '01J8XQK9V3KKKKKKKKKKKKKKKK')->postJson('/api/v1/checkout', checkoutBody($total, 'bacs'));
    $order = Order::query()->sole();

    $props = $this->actingAs($user)->get(route('orders.confirmation', $order->public_id))->assertOk()->viewData('page')['props'];
    expect($props['order']['order_number'])->toBe($order->order_number)
        // From orders.payment_method (02 §18), not the session: it survives sign-out.
        ->and($order->payment_method)->toBe('bacs')
        ->and($props['order']['payment_method'])->toBe('bacs')
        ->and($props['order']['lines'][0])->not->toHaveKey('unit_cost_e4')
        ->and($props['display_mode'])->toBe('net');

    $colleague = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $order->company_id, 'user_id' => $colleague->id]);
    $this->actingAs($colleague)->get(route('orders.confirmation', $order->public_id))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('orders.confirmation', $order->public_id))->assertForbidden();
});

it('shows public customers VAT-inclusive prices and trade buyers ex-VAT', function () {
    $public = User::factory()->create();
    $this->actingAs($public)->get('/cart')->assertOk();
    expect($this->actingAs($public)->get('/cart')->viewData('page')['props']['display_mode'])->toBe('gross');

    $trade = checkoutTradeBuyer();
    expect($this->actingAs($trade)->get('/cart')->viewData('page')['props']['display_mode'])->toBe('net');
});
