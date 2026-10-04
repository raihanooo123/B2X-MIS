<?php

use App\Models\Address;
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
use App\Models\TermsVersion;
use App\Models\User;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->customer = User::factory()->create();
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $location = Location::factory()->default()->create();
    $list = PriceList::factory()->create(['scope' => 'base']);
    $tax = TaxClass::factory()->create();
    TaxRate::factory()->for($tax)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $this->sku = Sku::factory()->create(['tax_class_id' => $tax->id]);
    $this->seed(DeliveryZoneSeeder::class);
    Pack::factory()->for($this->sku)->create(['base_units' => 1, 'gross_weight_g' => 500]);
    PriceListItem::factory()->for($list, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
    $this->terms = TermsVersion::factory()->sale()->create();
    $this->fields = ['contact_name' => 'Sam Lee', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'];
    $this->actingAs($this->customer)->post('/account/addresses', [...$this->fields, 'label' => 'Home'])->assertSessionHasNoErrors();
    $this->saved = Address::query()->where('user_id', $this->customer->id)->sole();
    $this->postJson('/api/v1/cart/lines', ['sku_id' => $this->sku->public_id, 'pack_qty' => 3])->assertSuccessful();
});

it('prefills only live owned addresses with the default first and supports manual entry', function () {
    $this->post('/account/addresses', [...$this->fields, 'label' => 'Work', 'is_default' => true])->assertSessionHasNoErrors();
    Address::factory()->create();
    $work = Address::query()->where('user_id', $this->customer->id)->where('label', 'Work')->sole();
    $this->get('/checkout')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('addresses', 2)->where('addresses.0.public_id', $work->public_id)->where('addresses.0.is_default', true));
    $this->saved->delete();
    $this->get('/checkout')->assertOk()->assertInertia(fn (Assert $page) => $page->has('addresses', 1));
});

it('snapshots final edited fields without changing the saved address or historical order', function () {
    $preview = $this->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN', 'delivery_address_id' => $this->saved->public_id])->assertOk();
    $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
        'payment_method' => 'bacs', 'expected_total_gross_minor' => $preview->json('total_gross_minor'),
        'terms_version_id' => $this->terms->id, 'delivery_address_id' => $this->saved->public_id,
        'delivery_address' => [...$this->fields, 'line1' => '9 Edited Road'],
    ])->assertCreated();
    $order = Order::query()->where('user_id', $this->customer->id)->sole();
    $snapshot = OrderAddress::query()->where('order_id', $order->id)->where('address_type', 'delivery')->sole();
    expect($snapshot->line1)->toBe('9 Edited Road')->and($this->saved->fresh()->line1)->toBe('1 High Street');
    $this->patch('/account/addresses/'.$this->saved->public_id, [...$this->fields, 'line1' => '15 New Road'])->assertSessionHasNoErrors();
    $this->delete('/account/addresses/'.$this->saved->public_id)->assertRedirect();
    expect($snapshot->fresh()->line1)->toBe('9 Edited Road');
});

it('rejects foreign and deleted saved address IDs on preview, intent and placement', function () {
    $foreign = Address::factory()->create();
    $this->saved->delete();
    $other = User::factory()->create();
    $peer = Address::factory()->delivery()->create(['company_id' => null, 'user_id' => $other->id, 'contact_name' => 'Other customer', 'country_code' => 'GB']);
    foreach ([$foreign->public_id, $peer->public_id, $this->saved->public_id] as $id) {
        $this->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN', 'delivery_address_id' => $id])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'delivery_address_id');
        $this->postJson('/api/v1/checkout/card-intent', ['expected_total_gross_minor' => 5000, 'delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN', 'delivery_address_id' => $id])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'delivery_address_id');
        $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
            'payment_method' => 'bacs', 'expected_total_gross_minor' => 5000, 'terms_version_id' => $this->terms->id,
            'delivery_address_id' => $id, 'delivery_address' => $this->fields,
        ])->assertStatus(422)->assertJsonPath('error.details.0.field', 'delivery_address_id');
    }
    expect(Order::query()->count())->toBe(0);
});

it('allows inline manual checkout without saving an address implicitly', function () {
    $preview = $this->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN'])->assertOk();
    $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
        'payment_method' => 'bacs', 'expected_total_gross_minor' => $preview->json('total_gross_minor'),
        'terms_version_id' => $this->terms->id, 'delivery_address' => [...$this->fields, 'line1' => 'Manual Road'],
    ])->assertCreated();
    expect(Address::query()->where('user_id', $this->customer->id)->count())->toBe(1);
});
