<?php

use App\Domain\Identity\EmailVerificationLink;
use App\Domain\Ordering\GuestOrderClaims;
use App\Domain\Ordering\GuestOrderLink;
use App\Http\Support\CartContext;
use App\Models\Company;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S5a: guest checkout (§6.1), the guest's order page by signed
 * link and Find my order (§6.2), saving details and claiming orders on
 * verification (§6.3), and `orders.guest_email` (02 §26.1).
 */
beforeEach(function () {
    $this->withoutVite();
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $location = Location::factory()->default()->create();
    $baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $this->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $this->seed(DeliveryZoneSeeder::class);
    Pack::factory()->for($this->sku)->create(['base_units' => 1, 'gross_weight_g' => 500]);
    PriceListItem::factory()->for($baseList, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
    $this->guest = [CartContext::SESSION_KEY => str_repeat('q', 64)];
});

function guestFill(): int
{
    test()->withSession(test()->guest)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => 3])->assertSuccessful();

    return (int) test()->withSession(test()->guest)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN'])->assertOk()->json('total_gross_minor');
}

/** @return array<string, mixed> */
function guestBody(int $total, array $overrides = []): array
{
    return array_replace_recursive([
        'payment_method' => 'card',
        'payment_intent_id' => 'pi_guesttest123',
        'expected_total_gross_minor' => $total,
        'guest_email' => 'guest@example.com',
        'delivery_address' => ['contact_name' => 'Sam Lee', 'phone' => '07700 900123', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'],
    ], $overrides);
}

function guestPlace(array $body)
{
    return test()->withSession(test()->guest)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', $body);
}

function guestOrder(string $email = 'guest@example.com', array $attributes = []): Order
{
    return Order::factory()->create(['company_id' => null, 'user_id' => null, 'guest_email' => $email] + $attributes);
}

it('refuses bank transfer for a guest, who pays by card only', function () {
    $terms = TermsVersion::factory()->sale()->create();
    $total = guestFill();

    $body = guestBody($total, ['payment_method' => 'bacs', 'terms_version_id' => $terms->id]);
    unset($body['payment_intent_id']);

    guestPlace($body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payment_method_not_available');
    expect(Order::query()->count())->toBe(0);
});

it('needs a guest email and phone from a guest, and refuses a guest email from a signed-in buyer', function () {
    $total = guestFill();

    $noEmail = guestBody($total);
    unset($noEmail['guest_email']);
    guestPlace($noEmail)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.0.field', 'guest_email');

    $noPhone = guestBody($total);
    unset($noPhone['delivery_address']['phone']);
    guestPlace($noPhone)->assertStatus(422)->assertJsonPath('error.details.0.field', 'delivery_address.phone');

    $this->actingAs(User::factory()->create())->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson('/api/v1/checkout', guestBody($total))
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'guest_email');
});

it('gives a guest the checkout page with card only and a sign-in offer back to checkout', function () {
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);

    $props = $this->get('/checkout')->assertOk()->viewData('page')['props'];
    expect($props['is_guest'])->toBeTrue()
        ->and(array_column($props['payment_methods'], 'value'))->toBe(['card'])
        ->and($props['pre_contract'])->not->toBeNull();

    $this->get('/checkout/sign-in')->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('checkout'));
});

it('opens a guest order only with a valid, unexpired link for its own email', function () {
    $order = guestOrder();
    $url = GuestOrderLink::url($order);

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Orders/Confirmation')->where('guest.email', 'guest@example.com'));

    // Tampered signature, and an expired link.
    $this->get(substr($url, 0, -1).(str_ends_with($url, '0') ? '1' : '0'))->assertRedirect(route('orders.lookup'));
    $this->get(GuestOrderLink::url($order, now()->subDays(91)->getTimestamp()))->assertRedirect(route('orders.lookup'));

    // A link issued for this order stops working if its email changes.
    DB::table('orders')->whereKey($order->id)->update(['guest_email' => 'other@example.com']);
    $this->get($url)->assertRedirect(route('orders.lookup'));
});

it('points emails at the guest link until the order belongs to an account', function () {
    $order = guestOrder();
    expect(GuestOrderLink::customerUrl($order))->toContain('/guest/');

    $order->update(['user_id' => User::factory()->create()->id]);
    expect(GuestOrderLink::customerUrl($order->fresh()))->toBe(route('orders.confirmation', ['order' => $order->public_id]));
});

it('emails a fresh link from Find my order only when the details match, with the same reply either way', function () {
    $order = guestOrder('guest@example.com');

    $this->post('/orders/lookup', ['order_number' => strtolower($order->order_number), 'email' => 'Guest@Example.com'])->assertSessionHas('status');
    $this->post('/orders/lookup', ['order_number' => $order->order_number, 'email' => 'someone@example.com'])->assertSessionHas('status');
    $this->post('/orders/lookup', ['order_number' => 'SO-NOPE', 'email' => 'guest@example.com'])->assertSessionHas('status');

    $sent = DB::table('notification_log')->where('notification_key', 'order.access_link')->get();
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->recipient)->toBe('guest@example.com')
        ->and((int) $sent->first()->subject_id)->toBe($order->id);
});

it('limits Find my order to 5 a minute per email', function () {
    $order = guestOrder();
    foreach (range(1, 5) as $i) {
        $this->post('/orders/lookup', ['order_number' => $order->order_number, 'email' => 'guest@example.com'])->assertSessionHasNoErrors();
    }

    $this->post('/orders/lookup', ['order_number' => $order->order_number, 'email' => 'guest@example.com'])->assertSessionHasErrors('email');
});

it('saves a guest\'s details as an unverified account, or emails an existing account, saying the same', function () {
    $order = guestOrder('new@example.com');
    $url = GuestOrderLink::url($order);
    $form = ['first_name' => 'Sam', 'last_name' => 'Lee', 'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple', 'terms' => true];

    $this->from($url)->post("{$url}/account", $form)->assertRedirect($url)->assertSessionHas('status');
    $user = User::query()->where('email', 'new@example.com')->sole();
    expect($user->email_verified_at)->toBeNull()
        ->and($order->fresh()->user_id)->toBeNull()
        ->and(DB::table('notification_log')->where('notification_key', 'auth.email_verification')->where('recipient', 'new@example.com')->exists())->toBeTrue();

    $existing = User::factory()->create(['email' => 'known@example.com']);
    $known = GuestOrderLink::url(guestOrder('known@example.com'));
    $this->from($known)->post("{$known}/account", $form)->assertRedirect($known)->assertSessionHas('status');
    expect(User::query()->where('email', 'known@example.com')->count())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'auth.existing_account')->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('claims a guest\'s orders when their email is verified, and audits each claim', function () {
    $user = User::factory()->unverified()->create(['email' => 'sam@example.com']);
    $mine = guestOrder('sam@example.com');
    $alsoMine = guestOrder('SAM@example.com');
    $theirs = guestOrder('other@example.com');
    $trade = Order::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => null, 'guest_email' => null]);

    $this->get(EmailVerificationLink::url($user))->assertRedirect();

    expect($mine->fresh()->user_id)->toBe($user->id)
        ->and($mine->fresh()->guest_email)->toBe('sam@example.com')
        ->and($alsoMine->fresh()->user_id)->toBe($user->id)
        ->and($theirs->fresh()->user_id)->toBeNull()
        ->and($trade->fresh()->user_id)->toBeNull();

    $audits = DB::table('audit_log')->where('action', 'order.claimed')->orderBy('subject_id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('subject_id')->map(fn ($id) => (int) $id)->all())->toBe([$mine->id, $alsoMine->id])
        ->and($audits->first()->event_family)->toBe('permission')
        ->and((int) $audits->first()->actor_user_id)->toBe($user->id)
        ->and(json_decode($audits->first()->after, true))->toBe(['user_id' => $user->id]);
});

it('claims nothing for an unverified email', function () {
    User::factory()->unverified()->create(['email' => 'sam@example.com']);
    $order = guestOrder('sam@example.com');

    (new GuestOrderClaims)->claimFor(User::query()->where('email', 'sam@example.com')->sole());

    expect($order->fresh()->user_id)->toBeNull();
});

it('refuses an order with no company, no user and no guest email (02 §26.1)', function () {
    expect(fn () => Order::factory()->create(['company_id' => null, 'user_id' => null, 'guest_email' => null]))
        ->toThrow(QueryException::class, 'orders_customer_chk');
});
