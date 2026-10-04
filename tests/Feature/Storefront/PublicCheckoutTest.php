<?php

use App\Domain\Notifications\Notices\OrderConfirmed;
use App\Domain\Notifications\Recipient;
use App\Domain\Storefront\Branding;
use App\Models\Address;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S4: terms of sale at checkout (§6.1 step 4, 02 §26.2), the
 * standard delivery snapshot (02 §26.3), the GB-only rule (§6.1 rule G),
 * and the pre-contract information on the page and in the confirmation
 * email (§7.1).
 */
beforeEach(function () {
    $this->withoutVite();
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $this->location = Location::factory()->default()->create();
    $baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);

    $this->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $this->seed(DeliveryZoneSeeder::class);
    Pack::factory()->for($this->sku)->create(['base_units' => 1, 'gross_weight_g' => 500]);
    PriceListItem::factory()->for($baseList, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
});

function s4Fill(User $user, int $packQty = 3): int
{
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => $packQty])->assertSuccessful();

    return (int) test()->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN'])->assertOk()->json('total_gross_minor');
}

/** @return array<string, mixed> */
function s4Body(int $expected, array $overrides = []): array
{
    return array_replace_recursive([
        'payment_method' => 'bacs',
        'expected_total_gross_minor' => $expected,
        'delivery_address' => ['contact_name' => 'Sam Lee', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'],
    ], $overrides);
}

function s4Place(User $user, array $body)
{
    return test()->actingAs($user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', $body);
}

function s4TradeBuyer(): User
{
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 10_000_000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    Address::factory()->default()->delivery()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    return $user;
}

it('records the accepted terms of sale and the standard delivery on a public order', function () {
    $terms = TermsVersion::factory()->sale()->create();
    $user = User::factory()->create();
    $total = s4Fill($user);

    s4Place($user, s4Body($total, ['terms_version_id' => $terms->id]))->assertCreated();

    $order = Order::query()->sole();
    $acceptance = TermsAcceptance::query()->sole();
    expect($acceptance->terms_version_id)->toBe($terms->id)
        ->and($acceptance->user_id)->toBe($user->id)
        ->and($acceptance->order_id)->toBe($order->id)
        ->and($acceptance->source)->toBe('checkout')
        ->and($acceptance->b2b_application_id)->toBeNull()
        ->and($acceptance->ip)->not->toBeNull()
        // One standard method is quoted and no upgrade exists, so the
        // standard charge is the carriage charged (02 §26.3).
        ->and($order->shipping_net_minor)->toBeGreaterThan(0)
        ->and($order->standard_shipping_net_minor)->toBe($order->shipping_net_minor);
});

it('records no terms of sale and no standard delivery on a trade order', function () {
    TermsVersion::factory()->sale()->create();
    $user = s4TradeBuyer();
    $total = s4Fill($user);

    s4Place($user, s4Body($total))->assertCreated();

    expect(Order::query()->sole()->standard_shipping_net_minor)->toBeNull()
        ->and(TermsAcceptance::query()->count())->toBe(0);
});

it('refuses a public order without accepted terms of sale, committing nothing', function () {
    TermsVersion::factory()->sale()->create();
    $user = User::factory()->create();
    $total = s4Fill($user);

    s4Place($user, s4Body($total))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'terms_not_accepted');

    expect(Order::query()->count())->toBe(0);
});

it('answers 409 terms_changed when the accepted version is no longer in force', function () {
    $old = TermsVersion::factory()->sale()->create(['effective_from' => now()->subDays(2)]);
    $current = TermsVersion::factory()->sale()->create(['effective_from' => now()->subDay()]);
    $user = User::factory()->create();
    $total = s4Fill($user);

    s4Place($user, s4Body($total, ['terms_version_id' => $old->id]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'terms_changed')
        ->assertJsonPath('error.details.0.meta.current_terms_version_id', $current->id);

    expect(Order::query()->count())->toBe(0)
        ->and(TermsAcceptance::query()->count())->toBe(0);
});

it('blocks public checkout while no terms of sale are published, but not trade', function () {
    $user = User::factory()->create();
    s4Fill($user);
    $codes = collect($this->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])->assertOk()->json('blockers'))->pluck('code');
    expect($codes)->toContain('terms_of_sale_unavailable');

    $trade = s4TradeBuyer();
    s4Fill($trade);
    $tradeCodes = collect($this->actingAs($trade)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])->assertOk()->json('blockers'))->pluck('code');
    expect($tradeCodes)->not->toContain('terms_of_sale_unavailable');
});

it('delivers public orders to GB only, on preview, card intent and checkout', function (string $country) {
    $terms = TermsVersion::factory()->sale()->create();
    $user = User::factory()->create();
    $total = s4Fill($user);

    $this->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => $country])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'country_not_served');

    config(['services.stripe.secret' => 'sk_test_x']);
    $this->actingAs($user)->postJson('/api/v1/checkout/card-intent', ['expected_total_gross_minor' => $total, 'delivery_country_code' => $country, 'delivery_postcode' => 'JE2 3AB'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'country_not_served');

    s4Place($user, s4Body($total, ['terms_version_id' => $terms->id, 'delivery_address' => ['country_code' => $country, 'postcode' => 'JE2 3AB']]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'country_not_served');

    expect(Order::query()->count())->toBe(0);
})->with(['JE', 'GG', 'IM', 'FR']);

it('leaves trade delivery countries alone', function () {
    $user = s4TradeBuyer();
    s4Fill($user);

    $this->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'JE'])
        ->assertOk();
});

it('gives a public buyer the terms, the pre-contract information and GB only on the checkout page', function () {
    $terms = TermsVersion::factory()->sale()->create(['version' => 'sale-7']);
    TaxRate::factory()->for(TaxClass::factory())->create(['country_code' => 'FR', 'rate_bp' => 2000]);
    SystemConfiguration::factory()->create(['config_key' => 'seller.legal_name', 'value_type' => 'text', 'value_int' => null, 'value_text' => 'Example Supplies Ltd']);
    $user = User::factory()->create();

    $props = $this->actingAs($user)->get('/checkout')->assertOk()->viewData('page')['props'];

    $headings = array_column($props['pre_contract']['sections'], 'heading');
    expect(array_column($props['countries'], 'code'))->toBe(['GB'])
        ->and($props['terms_of_sale']['id'])->toBe($terms->id)
        ->and($props['terms_of_sale']['version'])->toBe('sale-7')
        ->and($headings)->toContain('Who you are buying from', 'Your right to cancel', 'Returning goods', 'Terms of sale')
        ->and(json_encode($props['pre_contract']))->toContain('Example Supplies Ltd')
        // The return-cost statement (05.15 §7.2, reg. 35(5)).
        ->and(json_encode($props['pre_contract']))->toContain('you pay the direct cost of returning the goods');

    $trade = $this->actingAs(s4TradeBuyer())->get('/checkout')->assertOk()->viewData('page')['props'];
    expect($trade['terms_of_sale'])->toBeNull()
        ->and($trade['pre_contract'])->toBeNull()
        ->and(array_column($trade['countries'], 'code'))->toContain('FR');
});

it('repeats the pre-contract information and the model cancellation form in a public order confirmation', function () {
    $terms = TermsVersion::factory()->sale()->create(['version' => 'sale-3']);
    $user = User::factory()->create();
    s4Place($user, s4Body(s4Fill($user), ['terms_version_id' => $terms->id]))->assertCreated();
    $order = Order::query()->sole();

    // A newer version published afterwards does not change what was agreed.
    TermsVersion::factory()->sale()->create(['version' => 'sale-4', 'effective_from' => now()]);

    $mail = (new OrderConfirmed($order->id))->content(Recipient::user($user));
    $headings = array_column($mail->sections, 'heading');
    $text = json_encode($mail->sections);

    expect($headings)->toContain('Who you are buying from', 'Price', 'Your right to cancel', 'Returning goods', 'Complaints', 'Terms of sale', 'Model cancellation form')
        ->and($text)->toContain('version sale-3')
        ->and($text)->not->toContain('sale-4')
        ->and($text)->toContain(Branding::current()->name);
});

it('sends a trade order confirmation without consumer sections', function () {
    $user = s4TradeBuyer();
    s4Place($user, s4Body(s4Fill($user)))->assertCreated();

    expect((new OrderConfirmed(Order::query()->sole()->id))->content(Recipient::user($user))->sections)->toBe([]);
});

it('allows a missing user only on a checkout acceptance (02 §26.2)', function () {
    $terms = TermsVersion::factory()->sale()->create();
    $user = User::factory()->create();
    s4Place($user, s4Body(s4Fill($user), ['terms_version_id' => $terms->id]))->assertCreated();
    $order = Order::query()->sole();
    $other = Order::factory()->create();

    DB::table('terms_acceptances')->insert(['terms_version_id' => $terms->id, 'user_id' => null, 'order_id' => $other->id, 'source' => 'checkout']);
    expect(TermsAcceptance::query()->whereNull('user_id')->count())->toBe(1);

    // A trade application's acceptance, otherwise valid, still needs its user.
    $application = B2bApplication::factory()->create();
    // Each violation in its own savepoint, so the next one still reaches its constraint.
    expect(fn () => DB::transaction(fn () => DB::table('terms_acceptances')->insert(['terms_version_id' => $terms->id, 'user_id' => null, 'b2b_application_id' => $application->id, 'source' => 'trade_application'])))
        ->toThrow(QueryException::class, 'terms_acceptances_user_chk');

    // Never more than the carriage charged (02 §26.3).
    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update(['standard_shipping_net_minor' => $order->shipping_net_minor + 1])))
        ->toThrow(QueryException::class, 'orders_standard_shipping_chk');
});
