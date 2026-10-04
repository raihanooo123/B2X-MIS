<?php

use App\Domain\Billing\PaymentGateway;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\GuestOrderLink;
use App\Domain\Ordering\OrderCancellationService;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\TermsVersion;
use App\Models\User;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeCardGateway;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S6a — a consumer cancels before dispatch (05.4 §13.2): whole
 * order, stock released, card released or refunded after commit, receipt
 * credited, `order.cancelled` sent; trade orders and dispatched orders are
 * refused.
 */
beforeEach(function () {
    $this->withoutVite();
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
    $this->gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    NumberSequence::factory()->forSeries('receipt_number', 'RCP-')->create();
    // credit_note_number is created by migration 2026_10_18_090200.
    $this->location = Location::factory()->default()->create();
    $baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $this->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    $this->seed(DeliveryZoneSeeder::class);
    Pack::factory()->for($this->sku)->create(['base_units' => 1, 'gross_weight_g' => 500]);
    PriceListItem::factory()->for($baseList, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
    $this->terms = TermsVersion::factory()->sale()->create();
});

/** A public customer's card order, placed through checkout. */
function cancelCardOrder(User $user, bool $capture = true): Order
{
    test()->gateway->failCapture = ! $capture;
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => 3])->assertSuccessful();
    $total = (int) test()->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN'])->json('total_gross_minor');
    $intent = (string) test()->actingAs($user)
        ->postJson('/api/v1/checkout/card-intent', ['expected_total_gross_minor' => $total, 'delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN'])
        ->assertOk()->json('data.id');
    test()->gateway->authorise($intent);

    test()->actingAs($user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
        'payment_method' => 'card',
        'payment_intent_id' => $intent,
        'expected_total_gross_minor' => $total,
        'terms_version_id' => test()->terms->id,
        'delivery_address' => ['contact_name' => 'Sam Lee', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'],
    ])->assertCreated();
    test()->gateway->failCapture = false;

    return Order::query()->latest('id')->firstOrFail();
}

function cancelAvailable(): int
{
    return (int) StockLevel::query()->where('sku_id', test()->sku->id)->whereNull('batch_id')->value('available_base_qty');
}

it('cancels a paid card order: stock released, card refunded, receipt credited, customer told (C12)', function () {
    $user = User::factory()->create();
    $order = cancelCardOrder($user);
    expect($order->payment_status)->toBe('paid')
        ->and(cancelAvailable())->toBe(97);
    $receipt = Invoice::query()->where('order_id', $order->id)->sole();

    $this->actingAs($user)->from(route('orders.confirmation', $order->public_id))
        ->post(route('orders.cancel', $order->public_id))
        ->assertRedirect(route('orders.confirmation', $order->public_id))
        ->assertSessionHas('status');

    $order->refresh();
    $original = Payment::query()->where('order_id', $order->id)->where('type', 'payment')->sole();
    $refund = Payment::query()->where('order_id', $order->id)->where('type', 'refund')->sole();
    $credit = CreditNote::query()->sole();

    expect($order->status)->toBe('cancelled')
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($order->payment_status)->toBe('refunded')
        ->and(cancelAvailable())->toBe(100)
        ->and(StockMovement::query()->where('movement_type', 'deallocation')->where('reason_code', 'order_cancelled')->count())->toBe(1)
        ->and($refund->status)->toBe('captured')
        ->and($refund->amount_minor)->toBe($order->total_gross_minor)
        ->and($refund->refunded_payment_id)->toBe($original->id)
        ->and($refund->gateway_reference)->toStartWith('re_')
        ->and($original->status)->toBe('refunded')
        ->and($this->gateway->refunds)->toHaveCount(1)
        ->and($this->gateway->refunds[0]['amount_minor'])->toBe($order->total_gross_minor)
        ->and($credit->reason)->toBe('cancellation')
        ->and($credit->company_id)->toBeNull()
        ->and($credit->invoice_id)->toBe($receipt->id)
        ->and($credit->total_gross_minor)->toBe($receipt->total_gross_minor)
        ->and($credit->subtotal_net_minor + $credit->tax_minor)->toBe($credit->total_gross_minor)
        ->and($credit->credit_note_number)->toStartWith('CN-')
        ->and(DB::table('notification_log')->where('notification_key', 'order.cancelled')->value('recipient'))->toBe(strtolower($user->email));
});

it('releases an authorised card that was never captured, and refunds nothing', function () {
    $user = User::factory()->create();
    $order = cancelCardOrder($user, capture: false);
    $intent = Payment::query()->where('order_id', $order->id)->value('gateway_reference');
    expect($order->payment_status)->toBe('unpaid');

    (new OrderCancellationService)->cancel($order->id, $user->id);

    expect(Payment::query()->where('order_id', $order->id)->sole()->status)->toBe('voided')
        ->and($this->gateway->cancelled)->toContain($intent)
        ->and($this->gateway->refunds)->toBe([])
        ->and(CreditNote::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe('cancelled');
});

it('marks a refused card refund failed, keeps the cancellation, and tells accounts', function () {
    $accounts = User::factory()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', 'accounts')->first() ?? Role::factory()->create(['code' => 'accounts']))->id, 'user_id' => $accounts->id]);
    $user = User::factory()->create();
    $order = cancelCardOrder($user);
    $this->gateway->failRefund = true;

    (new OrderCancellationService)->cancel($order->id, $user->id);

    $refund = Payment::query()->where('order_id', $order->id)->where('type', 'refund')->sole();
    expect($refund->status)->toBe('failed')
        ->and($refund->failure_reason)->toContain('Card expired')
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and(DB::table('notification_log')->where('notification_key', 'refund.failed')->where('user_id', $accounts->id)->exists())->toBeTrue();
});

it('records a BACS refund for accounts to pay, with no gateway call', function () {
    $order = Order::factory()->create(['company_id' => null, 'status' => 'confirmed', 'payment_method' => 'bacs', 'payment_status' => 'paid', 'total_gross_minor' => 5000]);
    $paid = Payment::factory()->create(['order_id' => $order->id, 'company_id' => null, 'type' => 'payment', 'gateway' => 'bacs', 'gateway_reference' => null, 'status' => 'captured', 'amount_minor' => 5000]);

    (new OrderCancellationService)->cancel($order->id);

    $refund = Payment::query()->where('type', 'refund')->sole();
    expect($refund->gateway)->toBe('bacs')
        ->and($refund->status)->toBe('pending')
        ->and($refund->refunded_payment_id)->toBe($paid->id)
        ->and($this->gateway->refunds)->toBe([]);
});

it('refuses once anything is dispatched, and when already cancelled', function () {
    $dispatched = Order::factory()->create(['company_id' => null, 'status' => 'dispatched']);
    $partShipped = Order::factory()->create(['company_id' => null, 'status' => 'picking']);
    Shipment::factory()->create(['order_id' => $partShipped->id, 'status' => 'dispatched', 'dispatched_at' => now()]);
    $cancelled = Order::factory()->create(['company_id' => null, 'status' => 'cancelled']);

    $reason = function (Order $o): ?string {
        try {
            (new OrderCancellationService)->cancel($o->id);
        } catch (OrderNotCancellableException $e) {
            return $e->reason;
        }

        return null;
    };

    expect($reason($dispatched))->toBe('order_already_dispatched')
        ->and($reason($partShipped))->toBe('order_already_dispatched')
        ->and($reason($cancelled))->toBe('already_cancelled')
        ->and($partShipped->fresh()->status)->toBe('picking');
});

it('does not cancel a trade order (05.15 §12 Q11)', function () {
    $company = Company::factory()->create();
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id]);
    $order = Order::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id, 'status' => 'confirmed']);

    $this->actingAs($buyer)->post(route('orders.cancel', $order->public_id))->assertForbidden();
    expect(fn () => (new OrderCancellationService)->cancel($order->id))->toThrow(OrderNotCancellableException::class);
    expect($order->fresh()->status)->toBe('confirmed');
});

it('lets a guest cancel through their order link only', function () {
    $order = Order::factory()->create(['company_id' => null, 'user_id' => null, 'guest_email' => 'guest@example.com', 'status' => 'confirmed']);
    $url = GuestOrderLink::url($order);

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('order.can_cancel', true)->where('cancel_url', "{$url}/cancel"));

    $this->post(substr($url, 0, -1).(str_ends_with($url, '0') ? '1' : '0').'/cancel')->assertRedirect(route('orders.lookup'));
    expect($order->fresh()->status)->toBe('confirmed');

    $this->from($url)->post("{$url}/cancel")->assertRedirect($url);
    expect($order->fresh()->status)->toBe('cancelled')
        ->and(DB::table('notification_log')->where('notification_key', 'order.cancelled')->value('recipient'))->toBe('guest@example.com');
});

it('offers cancellation on the order page only while a consumer order is undispatched', function () {
    $user = User::factory()->create();
    $open = Order::factory()->create(['company_id' => null, 'user_id' => $user->id, 'status' => 'confirmed']);
    $sent = Order::factory()->create(['company_id' => null, 'user_id' => $user->id, 'status' => 'dispatched']);

    $this->actingAs($user)->get(route('orders.confirmation', $open->public_id))
        ->assertInertia(fn ($page) => $page->where('order.can_cancel', true)->where('cancel_url', route('orders.cancel', $open->public_id)));
    $this->actingAs($user)->get(route('orders.confirmation', $sent->public_id))
        ->assertInertia(fn ($page) => $page->where('order.can_cancel', false)->where('cancel_url', null));
});
