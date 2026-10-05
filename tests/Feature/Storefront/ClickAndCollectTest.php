<?php

use App\Domain\Billing\PaymentGateway;
use App\Domain\Collection\CashAtCollection;
use App\Domain\Collection\CashRefused;
use App\Domain\Collection\CollectionExpiry;
use App\Domain\Collection\CollectionSettings;
use App\Domain\Collection\CollectionSlots;
use App\Domain\Collection\DailyCashReport;
use App\Domain\Collection\PayAtCollectionEligibility;
use App\Domain\Collection\PayAtCollectionSuspensions;
use App\Domain\Notifications\Notices\OrderConfirmed;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\OrderCancellationService;
use App\Domain\Ordering\PartialCancellations;
use App\Domain\Returns\CancellationEligibility;
use App\Domain\Returns\PossessionDay;
use App\Domain\Warehouse\DispatchDetails;
use App\Domain\Warehouse\DispatchService;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Domain\Warehouse\PickConfirmationService;
use App\Domain\Warehouse\PickListGenerator;
use App\Http\Support\CartContext;
use App\Models\Address;
use App\Models\CollectionBooking;
use App\Models\CollectionSlot;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
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
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\TermsVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\FakeCardGateway;

uses(RefreshDatabase::class);

/**
 * 05.6 §7A (S5b), 05.15 §6.1: collection slots as generated rows; booking
 * in invariant 6's lock order; public, guest and trade collection; pay at
 * collection — eligibility, placement confirmed and unpaid, the counter's
 * cash, receipt or invoice, handover; the expiry sweep and no-show
 * suspension; the daily cash report; possession from `collected_at`.
 *
 * The clock is fixed on Monday 5 October 2026, 08:00 UK time (BST).
 */
beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'Europe/London'));
    config(['services.stripe.key' => 'pk_test_placeholder', 'services.stripe.secret' => 'sk_test_placeholder']);
    $this->gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    NumberSequence::factory()->forSeries('receipt_number', 'RCP-')->create();
    NumberSequence::factory()->forSeries('invoice_number', 'INV-')->create();
    // credit_note_number is created by migration 2026_10_18_090200.
    foreach (['seller.legal_name' => 'Test Wholesale Ltd', 'seller.address' => "1 Test Street\nLondon\nE1 6AN", 'seller.vat_number' => 'GB123456789'] as $key => $value) {
        SystemConfiguration::factory()->create(['config_key' => $key, 'value_type' => 'text', 'value_int' => null, 'value_text' => $value]);
    }

    $this->location = Location::factory()->default()->create(['name' => 'Trade counter']);
    $taxClass = TaxClass::factory()->create();
    // Time-ranged rows default to the database's real now(), later than the frozen clock: start them earlier.
    $validity = '[2026-01-01 00:00:00+00,)';
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000, 'validity' => $validity]);
    $this->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id, 'is_stock_tracked' => true, 'tracking_mode' => 'none']);
    Pack::factory()->for($this->sku)->create(['base_units' => 1, 'gross_weight_g' => 500]);
    $base = PriceList::factory()->create(['scope' => 'base', 'validity' => $validity]);
    PriceListItem::factory()->for($base, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);

    // 05.6 §7A.2 step 3: the `collection` method, charged below the free threshold.
    $this->seed(DeliveryZoneSeeder::class);
    $zone = DeliveryZone::query()->where('code', 'GB_MAINLAND')->firstOrFail();
    DeliveryRate::factory()->method('collection')->weightBand(0, null)->create(['zone_id' => $zone->id, 'tax_class_id' => $taxClass->id, 'price_net_minor' => 500, 'validity' => $validity]);
    ccConfig(CollectionSettings::CASH_ENABLED, 1);

    $this->terms = TermsVersion::factory()->sale()->create();
    $this->guest = [CartContext::SESSION_KEY => str_repeat('c', 64)];
    // Saturday 10 October, 09:00–12:00 UK: open, capacity 3.
    $this->slot = ccSlot('2026-10-10', '09:00', '12:00', 3);
});

function ccConfig(string $key, int $value, ?int $locationId = null): void
{
    SystemConfiguration::query()->updateOrCreate(
        ['config_key' => $key, 'scope' => $locationId === null ? 'global' : 'location', 'location_id' => $locationId, 'company_id' => null],
        ['value_type' => 'int', 'value_int' => $value, 'value_text' => null],
    );
}

function ccSlot(string $date, string $start, string $end, int $capacity = 3, string $status = 'open', ?int $locationId = null): CollectionSlot
{
    return CollectionSlot::query()->create([
        'location_id' => $locationId ?? test()->location->id,
        'slot_date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'capacity' => $capacity,
        'status' => $status,
    ]);
}

/** The buyer's client: signed in, or a guest carrying their cart in the session. */
function ccClient(?User $user)
{
    return $user === null ? test()->withSession(test()->guest) : test()->actingAs($user);
}

function ccStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

function ccTradeBuyer(string $terms = 'net30'): User
{
    $company = Company::factory()->create(['payment_terms' => $terms, 'credit_limit_minor' => 10_000_000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
    Address::factory()->default()->billing()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    return $user;
}

/** Cart of three, previewed for collection; returns the preview. */
function ccFill(?User $user, ?int $slotId = null, ?string $method = null, int $packQty = 3): array
{
    ccClient($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => $packQty])->assertSuccessful();

    return ccPreview($user, $slotId ?? test()->slot->id, $method);
}

function ccPreview(?User $user, ?int $slotId, ?string $method = null): array
{
    return ccClient($user)->postJson('/api/v1/checkout/preview', array_filter([
        'fulfilment_type' => 'collection',
        'collection_slot_id' => $slotId,
        'payment_method' => $method,
    ]))->assertOk()->json();
}

/** @param  array<string, mixed>  $extra */
function ccPlace(?User $user, int $total, string $method = 'cash_at_collection', ?int $slotId = null, array $extra = [])
{
    $isTrade = $user !== null && CompanyUser::query()->where('user_id', $user->id)->exists();

    return ccClient($user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', array_merge([
        'payment_method' => $method,
        'expected_total_gross_minor' => $total,
        'fulfilment_type' => 'collection',
        'collection_slot_id' => $slotId ?? test()->slot->id,
    ], $isTrade ? [] : ['terms_version_id' => test()->terms->id], $extra));
}

/** A placed pay-at-collection order for a signed-in public customer. */
function ccCashOrder(?User $user = null): Order
{
    $user ??= User::factory()->create();
    $total = (int) ccFill($user)['total_gross_minor'];
    ccPlace($user, $total)->assertCreated();

    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

function ccPick(Order $order): Shipment
{
    $shipment = (new PickListGenerator)->open($order->id, test()->location->id);
    $allocations = StockAllocation::query()->whereIn('order_line_id', $order->lines()->pluck('id'))->orderBy('id')->pluck('id');
    foreach ($allocations as $allocationId) {
        (new PickConfirmationService)->confirm($shipment, (int) $allocationId);
    }

    return $shipment->fresh();
}

/**
 * Every `FOR UPDATE`, by table, grouped per transaction (savepoints included).
 *
 * @return list<list<string>>
 */
function ccLockSegments(callable $work): array
{
    $segments = [[]];
    Event::listen(TransactionBeginning::class, function () use (&$segments): void {
        $segments[] = [];
    });
    DB::listen(function (QueryExecuted $query) use (&$segments): void {
        if (stripos($query->sql, 'for update') === false) {
            return;
        }
        preg_match('/from\s+"?([a-z_]+)"?/i', $query->sql, $match);
        $segments[array_key_last($segments)][] = strtolower($match[1] ?? '?');
    });
    $work();

    return array_values(array_filter($segments));
}

/**
 * 05.6 §7A.4: every transaction's lock sequence is an ordered subsequence
 * of the global list; a row already held may be locked again.
 *
 * @param  list<list<string>>  $segments
 */
function ccAssertLockOrder(array $segments): void
{
    $global = ['companies', 'orders', 'collection_slots', 'collection_bookings', 'shipments', 'stock_allocations', 'stock_levels', 'stock_serials', 'payments', 'number_sequences'];
    foreach ($segments as $segment) {
        $seen = array_values(array_unique(array_values(array_filter($segment, fn (string $t) => in_array($t, $global, true)))));
        $ranks = array_map(fn (string $t) => array_search($t, $global, true), $seen);
        $sorted = $ranks;
        sort($sorted);
        expect($ranks)->toBe($sorted, 'Locks out of order: '.implode(' → ', $seen));
    }
}

// -----------------------------------------------------------------
// §7A.1 slots
// -----------------------------------------------------------------

it('generates slots from the weekly pattern, idempotently, with DST handled once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/London'));
    SystemConfiguration::query()->create([
        'config_key' => CollectionSettings::SLOT_PATTERN, 'scope' => 'location', 'location_id' => $this->location->id,
        'value_type' => 'json', 'value_json' => ['sat' => [['start' => '09:00', 'end' => '10:00', 'capacity' => 4]], 'mon' => [['start' => '09:00', 'end' => '10:00', 'capacity' => 4]], 'sun' => []],
    ]);
    $slots = new CollectionSlots;

    $created = $slots->generate();
    expect($created)->toBeGreaterThan(0)
        ->and($slots->generate())->toBe(0);

    // The clocks go back on Sunday 25 October: 09:00 BST is 08:00Z, 09:00 GMT is 09:00Z.
    $before = CollectionSlot::query()->where('slot_date', '2026-10-24')->sole();
    $after = CollectionSlot::query()->where('slot_date', '2026-10-26')->sole();
    expect($slots->startUtc($before)->toIso8601String())->toBe('2026-10-24T08:00:00+00:00')
        ->and($slots->startUtc($after)->toIso8601String())->toBe('2026-10-26T09:00:00+00:00')
        ->and($slots->paymentDueBy($after)->toIso8601String())->toBe('2026-10-26T11:00:00+00:00')
        ->and(CollectionSlot::query()->where('slot_date', '2026-10-25')->exists())->toBeFalse();

    // A pattern change never touches a generated slot.
    DB::table('collection_slots')->where('id', $before->id)->update(['booked_count' => 2]);
    SystemConfiguration::query()->where('config_key', CollectionSettings::SLOT_PATTERN)->update(['value_json' => json_encode(['sat' => [['start' => '09:00', 'end' => '11:00', 'capacity' => 9]]])]);
    $slots->generate();
    expect(DB::table('collection_slots')->where('id', $before->id)->first(['end_time', 'capacity', 'booked_count']))
        ->toEqual((object) ['end_time' => '10:00:00', 'capacity' => 4, 'booked_count' => 2]);
});

it('refuses with 422 slot_unavailable a slot that does not exist, is closed, full or already started', function (string $case) {
    $slot = match ($case) {
        'unknown' => null,
        'closed' => ccSlot('2026-10-10', '13:00', '14:00', 3, 'closed'),
        'full' => ccSlot('2026-10-10', '14:00', '15:00', 1, 'full'),
        'started' => ccSlot('2026-10-05', '08:30', '09:30', 3),
    };
    $user = User::factory()->create();
    $total = (int) ccFill($user)['total_gross_minor'];

    ccPlace($user, $total, 'cash_at_collection', $slot?->id ?? 999999)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'slot_unavailable');

    expect(Order::query()->count())->toBe(0)
        ->and(CollectionBooking::query()->count())->toBe(0);
})->with(['unknown', 'closed', 'full', 'started']);

it('offers only open slots with room, after the minimum notice, within the horizon', function () {
    ccSlot('2026-10-05', '09:00', '10:00');            // inside the 2-hour notice
    ccSlot('2026-10-06', '09:00', '10:00', 1, 'full');
    ccSlot('2026-10-07', '09:00', '10:00', 3, 'closed');
    ccSlot('2026-10-19', '09:00', '10:00');            // day 14: beyond the horizon
    $offered = ccSlot('2026-10-05', '10:00', '11:00'); // exactly two hours ahead

    $ids = array_column($this->getJson('/api/v1/collection-slots')->assertOk()->json('data'), 'id');

    expect($ids)->toBe([$offered->id, $this->slot->id]);
});

// -----------------------------------------------------------------
// §7A.2 checkout with collection
// -----------------------------------------------------------------

it('lets a guest collect, paying by card only, with no address', function () {
    $preview = ccFill(null);
    expect($preview['blockers'])->toBe([])
        ->and($preview['collection']['pay_at_collection']['available'])->toBeFalse()
        ->and($preview['collection']['pay_at_collection']['rule'])->toBe('signed_in')
        ->and($preview['delivery']['method'])->toBe('collection')
        ->and($preview['delivery']['shipping_net_minor'])->toBe(500);
    $total = (int) $preview['total_gross_minor'];

    ccPlace(null, $total, 'cash_at_collection', null, ['guest_email' => 'guest@example.test'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'pay_at_collection_not_eligible');

    $intent = (string) ccClient(null)->postJson('/api/v1/checkout/card-intent', ['expected_total_gross_minor' => $total, 'fulfilment_type' => 'collection', 'collection_slot_id' => $this->slot->id])
        ->assertOk()->json('data.id');
    $this->gateway->authorise($intent);
    ccPlace(null, $total, 'card', null, ['guest_email' => 'guest@example.test', 'payment_intent_id' => $intent])->assertCreated();

    $order = Order::query()->sole();
    $booking = CollectionBooking::query()->sole();
    expect($order->fulfilment_type)->toBe('collection')
        ->and($order->guest_email)->toBe('guest@example.test')
        ->and($order->standard_shipping_net_minor)->toBe(0)
        ->and($order->addresses()->count())->toBe(0)
        ->and($booking->company_id)->toBeNull()
        ->and($booking->payment_due_by)->toBeNull()
        ->and(DB::table('collection_slots')->where('id', $this->slot->id)->value('booked_count'))->toBe(1);
});

it('snapshots a trade collection\'s default billing address and books it for the company', function () {
    $user = ccTradeBuyer();
    $companyId = (int) CompanyUser::query()->where('user_id', $user->id)->value('company_id');
    $total = (int) ccFill($user)['total_gross_minor'];

    ccPlace($user, $total, 'on_account')->assertCreated();

    $order = Order::query()->sole();
    expect($order->addresses()->pluck('address_type')->all())->toBe(['billing'])
        ->and(CollectionBooking::query()->sole()->company_id)->toBe($companyId)
        ->and($order->payment_status)->toBe('on_account');
});

it('allocates a collection only at the slot\'s location', function () {
    $other = Location::factory()->create(['name' => 'Second counter']);
    $slot = ccSlot('2026-10-10', '09:00', '10:00', 3, 'open', $other->id);
    $user = User::factory()->create();
    $preview = ccFill($user, $slot->id);

    // The default location has stock; the slot's location has none.
    expect(array_column($preview['blockers'], 'code'))->toContain('insufficient_stock');

    StockLevel::factory()->for($this->sku)->for($other)->create(['on_hand_base_qty' => 10, 'allocated_base_qty' => 0]);
    $total = (int) ccPreview($user, $slot->id)['total_gross_minor'];
    ccPlace($user, $total, 'cash_at_collection', $slot->id)->assertCreated();

    expect(StockAllocation::query()->pluck('location_id')->unique()->all())->toBe([$other->id]);
});

// -----------------------------------------------------------------
// §7A.3 pay at collection
// -----------------------------------------------------------------

it('places pay at collection confirmed and unpaid, with the deadline snapshotted', function () {
    $order = ccCashOrder();
    $booking = CollectionBooking::query()->sole();

    expect($order->status)->toBe('confirmed')
        ->and($order->payment_status)->toBe('unpaid')
        ->and($order->payment_method)->toBe('cash_at_collection')
        // Saturday 12:00 BST + 60 minutes.
        ->and($booking->payment_due_by?->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        ->and(Invoice::query()->count())->toBe(0);

    // A later grace change doesn't move an existing deadline.
    ccConfig(CollectionSettings::CASH_GRACE_MINUTES, 600);
    expect(CollectionBooking::query()->sole()->payment_due_by?->toIso8601String())->toBe('2026-10-10T12:00:00+00:00');
});

it('refuses each eligibility rule with its reason', function (string $rule) {
    $user = User::factory()->create();
    $companyId = null;
    match ($rule) {
        'enabled' => ccConfig(CollectionSettings::CASH_ENABLED, 0),
        'signed_in' => $user = null,
        'verified' => $user->forceFill(['email_verified_at' => null])->save(),
        'suspended' => DB::table('pay_at_collection_suspensions')->insert(['user_id' => $user->id, 'reason' => 'no_shows', 'no_show_count' => 2]),
        'standing' => $companyId = Company::factory()->create(['status' => 'suspended'])->id,
        'limit' => ccConfig(CollectionSettings::CASH_LIMIT, 999),
    };

    $refusal = (new PayAtCollectionEligibility)->refusal($user?->id, $companyId, $this->location->id, 1000);

    expect($refusal?->rule)->toBe($rule)
        ->and($refusal?->getMessage())->not->toBe('');
})->with(['enabled', 'signed_in', 'verified', 'suspended', 'standing', 'limit']);

it('accepts a total exactly at the limit and refuses a penny over', function () {
    $user = User::factory()->create();
    $total = (int) ccFill($user)['total_gross_minor'];

    ccConfig(CollectionSettings::CASH_LIMIT, $total - 1);
    expect(ccPreview($user, $this->slot->id, 'cash_at_collection')['collection']['pay_at_collection']['rule'])->toBe('limit');
    ccPlace($user, $total)->assertStatus(422)->assertJsonPath('error.code', 'pay_at_collection_not_eligible');

    ccConfig(CollectionSettings::CASH_LIMIT, $total);
    ccPlace($user, $total)->assertCreated();
});

it('names "Pay cash at collection", the amount and the deadline before ordering and in the confirmation', function () {
    $user = User::factory()->create();
    $preview = ccFill($user, null, 'cash_at_collection');
    $cash = collect($preview['collection']['pre_contract']['cash']);
    $payment = implode(' ', $cash->firstWhere('heading', 'Payment and delivery')['paragraphs']);
    $cancel = implode(' ', $cash->firstWhere('heading', 'Your right to cancel')['paragraphs']);

    expect($payment)->toContain('Pay cash at collection')->toContain('£10.44')->toContain('Saturday 10 October 2026, 13:00')
        ->and($cancel)->toContain('collect the goods')->toContain('nothing to refund');

    ccPlace($user, (int) $preview['total_gross_minor'])->assertCreated();
    $mail = (new OrderConfirmed(Order::query()->sole()->id))->content(new Recipient($user->email));
    $text = json_encode($mail, JSON_UNESCAPED_UNICODE);

    expect($text)->toContain('Pay cash at collection')->toContain('cash only')->toContain('Saturday 10 October 2026, 13:00');
});

// -----------------------------------------------------------------
// §7A.4 lock order
// -----------------------------------------------------------------

it('locks companies → collection_slots → stock_levels at placement (invariant 6)', function () {
    $user = ccTradeBuyer();
    $total = (int) ccFill($user)['total_gross_minor'];

    $segments = ccLockSegments(fn () => ccPlace($user, $total, 'on_account')->assertCreated());
    $placement = collect($segments)->first(fn (array $s) => in_array('collection_slots', $s, true));

    expect($placement)->not->toBeNull();
    $order = array_values(array_unique(array_intersect($placement, ['companies', 'collection_slots', 'stock_levels'])));
    expect($order)->toBe(['companies', 'collection_slots', 'stock_levels']);
    ccAssertLockOrder($segments);
});

it('keeps the global lock order on cash recording, handover, expiry and cancellation', function () {
    $staff = ccStaff('warehouse');
    $first = ccCashOrder();
    ccPick($first);
    $cash = ccLockSegments(fn () => (new CashAtCollection)->record($first->id, $staff->id, $first->total_gross_minor));
    ccAssertLockOrder($cash);
    expect($cash[0])->toBe(['orders', 'collection_bookings']);

    $shipment = Shipment::query()->where('order_id', $first->id)->sole();
    $handover = ccLockSegments(fn () => (new DispatchService)->dispatch($shipment, new DispatchDetails(actorUserId: $staff->id)));
    ccAssertLockOrder($handover);
    expect(array_slice(array_values(array_unique($handover[0])), 0, 3))->toBe(['orders', 'collection_bookings', 'shipments']);

    $expiring = ccCashOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:01', 'Europe/London'));
    $expiry = ccLockSegments(fn () => (new CollectionExpiry)->sweep());
    ccAssertLockOrder($expiry);
    expect(collect($expiry)->flatten()->all())->not->toContain('collection_slots')
        ->and($expiring->fresh()->status)->toBe('cancelled');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'Europe/London'));
    $cancelling = ccCashOrder();
    $cancel = ccLockSegments(fn () => (new OrderCancellationService)->cancel($cancelling->id));
    ccAssertLockOrder($cancel);
    expect(array_slice(array_values(array_unique($cancel[0])), 0, 3))->toBe(['orders', 'collection_slots', 'collection_bookings']);
});

// -----------------------------------------------------------------
// §7A.5 expiry
// -----------------------------------------------------------------

it('expires exactly after payment_due_by, releasing the stock, and not a minute before', function () {
    $order = ccCashOrder();
    $expiry = new CollectionExpiry;

    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:59', 'Europe/London'));
    expect($expiry->sweep())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00', 'Europe/London'));
    expect($expiry->sweep())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00:01', 'Europe/London'));
    expect($expiry->sweep())->toBe(1);

    $booking = CollectionBooking::query()->sole();
    expect($order->fresh()->status)->toBe('cancelled')
        ->and($booking->status)->toBe('no_show')
        ->and($booking->no_show_at)->not->toBeNull()
        ->and(DB::table('order_cancellations')->where('order_id', $order->id)->value('reason_code'))->toBe('collection_expired')
        ->and(DB::table('stock_levels')->where('sku_id', $this->sku->id)->value('allocated_base_qty'))->toBe(0)
        // The slot has passed and its capacity was used.
        ->and(DB::table('collection_slots')->where('id', $this->slot->id)->value('booked_count'))->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'collection.expired')->count())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'order.cancelled')->count())->toBe(0);
});

it('lets exactly one of the counter and the sweep win', function () {
    $staff = ccStaff('warehouse');
    $paid = ccCashOrder();
    $late = ccCashOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:30', 'Europe/London'));

    // Past the deadline but before the sweep: payment accepted, and the sweep then skips it.
    (new CashAtCollection)->record($paid->id, $staff->id, $paid->total_gross_minor);
    (new CollectionExpiry)->sweep();
    expect($paid->fresh()->status)->toBe('confirmed')
        ->and($paid->fresh()->payment_status)->toBe('paid')
        ->and($late->fresh()->status)->toBe('cancelled');

    // After the sweep: refused.
    expect(fn () => (new CashAtCollection)->record($late->id, $staff->id, $late->total_gross_minor))
        ->toThrow(CashRefused::class, 'expired and its stock was released');
    expect(Payment::query()->where('order_id', $late->id)->count())->toBe(0);
});

// -----------------------------------------------------------------
// §7A.6 the counter
// -----------------------------------------------------------------

it('records cash once, issues the receipt allocated to it, then hands over', function () {
    $staff = ccStaff('warehouse');
    $order = ccCashOrder();
    $shipment = ccPick($order);

    // Handover refused while unpaid.
    expect(fn () => (new DispatchService)->dispatch($shipment, new DispatchDetails(actorUserId: $staff->id)))
        ->toThrow(FulfilmentRejectedException::class, 'must be paid');

    $first = (new CashAtCollection)->record($order->id, $staff->id, $order->total_gross_minor);
    $second = (new CashAtCollection)->record($order->id, $staff->id, $order->total_gross_minor);

    $payment = Payment::query()->sole();
    $receipt = Invoice::query()->sole();
    expect($second->replayed)->toBeTrue()
        ->and($second->payment->id)->toBe($first->payment->id)
        ->and($payment->gateway)->toBe('cash')
        ->and($payment->status)->toBe('captured')
        ->and($payment->amount_minor)->toBe($order->total_gross_minor)
        ->and($payment->recorded_by_user_id)->toBe($staff->id)
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and($receipt->invoice_number)->toStartWith('RCP-')
        ->and($receipt->status)->toBe('paid')
        ->and($receipt->paid_minor)->toBe($order->total_gross_minor)
        ->and(DB::table('payment_allocations')->where('payment_id', $payment->id)->sum('amount_minor'))->toBe($order->total_gross_minor)
        ->and(DB::table('audit_log')->where('action', 'payment.cash_recorded')->where('subject_id', $payment->id)->value('event_family'))->toBe('permission');

    (new DispatchService)->dispatch($shipment->fresh(), new DispatchDetails(actorUserId: $staff->id, collectorName: 'Alex Lee'));

    $booking = CollectionBooking::query()->sole();
    expect($booking->status)->toBe('collected')
        ->and($booking->collected_at)->not->toBeNull()
        ->and($booking->handed_over_by_user_id)->toBe($staff->id)
        ->and($booking->collector_name)->toBe('Alex Lee')
        ->and($order->fresh()->status)->toBe('dispatched')
        ->and(DB::table('notification_log')->where('notification_key', 'shipment.dispatched')->count())->toBe(0);
});

it('refuses to hand over an unpaid card collection', function () {
    $staff = ccStaff('warehouse');
    $user = User::factory()->create();
    $total = (int) ccFill($user)['total_gross_minor'];
    $intent = (string) $this->actingAs($user)->postJson('/api/v1/checkout/card-intent', ['expected_total_gross_minor' => $total, 'fulfilment_type' => 'collection', 'collection_slot_id' => $this->slot->id])->json('data.id');
    $this->gateway->authorise($intent);
    ccPlace($user, $total, 'card', null, ['payment_intent_id' => $intent])->assertCreated();
    $order = Order::query()->sole();
    expect($order->payment_status)->toBe('paid');
    $shipment = ccPick($order);
    DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'unpaid']);

    try {
        (new DispatchService)->dispatch($shipment, new DispatchDetails(actorUserId: $staff->id));
        $this->fail('An unpaid card collection was handed over.');
    } catch (FulfilmentRejectedException $e) {
        expect($e->errorCode)->toBe('awaiting_payment');
    }
    expect(CollectionBooking::query()->sole()->status)->toBe('booked');
});

it('voids a wrongly keyed cash payment before handover, reversing its allocation, and records it again', function () {
    $counter = ccStaff('warehouse');
    $accounts = ccStaff('accounts');
    $order = ccCashOrder();
    $first = (new CashAtCollection)->record($order->id, $counter->id, $order->total_gross_minor);

    (new CashAtCollection)->void($first->payment->id, $accounts->id, 'Keyed against the wrong order');

    $receipt = Invoice::query()->sole();
    expect($first->payment->fresh()->status)->toBe('voided')
        ->and($order->fresh()->payment_status)->toBe('unpaid')
        ->and($receipt->paid_minor)->toBe(0)
        ->and($receipt->status)->toBe('issued')
        ->and(DB::table('payment_allocations')->where('payment_id', $first->payment->id)->where('reason_code', 'cash_voided')->value('amount_minor'))->toBe(-$order->total_gross_minor)
        ->and(DB::table('audit_log')->where('action', 'payment.cash_voided')->value('reason'))->toBe('Keyed against the wrong order');

    $again = (new CashAtCollection)->record($order->id, $counter->id, $order->total_gross_minor);
    expect($again->payment->id)->not->toBe($first->payment->id)
        ->and($again->document?->id)->toBe($receipt->id)
        ->and($receipt->fresh()->status)->toBe('paid')
        ->and(Invoice::query()->count())->toBe(1);

    // After handover a mistake is a refund, never an edit.
    (new DispatchService)->dispatch(ccPick($order), new DispatchDetails(actorUserId: $counter->id));
    expect(fn () => (new CashAtCollection)->void($again->payment->id, $accounts->id, 'Too late'))
        ->toThrow(CashRefused::class, 'handed over');
});

it('reconciles the receipt with a credit note for what was cancelled before the cash was taken', function () {
    $staff = ccStaff('warehouse');
    $user = User::factory()->create();
    $order = ccCashOrder($user);

    // 05.10 §2: one of the three packs cancelled before collection, so before any receipt exists.
    $cancellation = (new PartialCancellations)->cancel($order->id, [1 => 1], 'customer', $user->id, CarbonImmutable::now());
    $due = CashAtCollection::amountDueMinor($order->fresh());
    expect($due)->toBe($order->total_gross_minor - $cancellation->cancelled_gross_minor)
        ->and($due)->toBeLessThan($order->total_gross_minor);

    // The full total is refused; the reduced amount is taken.
    expect(fn () => (new CashAtCollection)->record($order->id, $staff->id, $order->total_gross_minor))
        ->toThrow(CashRefused::class, 'does not match');
    $recorded = (new CashAtCollection)->record($order->id, $staff->id, $due);

    $receipt = Invoice::query()->sole();
    $creditNote = DB::table('credit_notes')->where('invoice_id', $receipt->id)->sole();
    expect($recorded->payment->amount_minor)->toBe($due)
        ->and($receipt->total_gross_minor)->toBe($order->total_gross_minor)
        ->and($creditNote->reason)->toBe('cancellation')
        ->and($creditNote->total_gross_minor)->toBe($cancellation->cancelled_gross_minor)
        ->and($creditNote->subtotal_net_minor + $creditNote->tax_minor)->toBe($creditNote->total_gross_minor)
        // document − credit notes = amount paid
        ->and($receipt->total_gross_minor - $creditNote->total_gross_minor)->toBe($recorded->payment->amount_minor)
        ->and($receipt->paid_minor)->toBe($due)
        ->and($receipt->status)->toBe('paid')
        ->and($order->fresh()->payment_status)->toBe('paid');
});

it('refuses a cash amount that does not match the amount due', function () {
    $staff = ccStaff('warehouse');
    $order = ccCashOrder();

    expect(fn () => (new CashAtCollection)->record($order->id, $staff->id, $order->total_gross_minor - 1))
        ->toThrow(CashRefused::class, 'does not match');
    expect(Payment::query()->count())->toBe(0);
});

it('serves the counter screen to warehouse staff and takes the cash through it', function () {
    $staff = ccStaff('warehouse');
    $order = ccCashOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Europe/London'));

    $this->actingAs($staff)->get('/warehouse/collections')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Warehouse/Collections', false)
            ->where('bookings.0.order_number', $order->order_number)
            ->where('bookings.0.amount_due_minor', $order->total_gross_minor));

    $this->actingAs($staff)->post(route('warehouse.collections.cash', ['order' => $order->public_id]), ['amount_minor' => $order->total_gross_minor, 'received' => '1'])
        ->assertSessionHasNoErrors();
    expect($order->fresh()->payment_status)->toBe('paid');

    $this->actingAs($staff)->post(route('warehouse.collections.handover', ['order' => $order->public_id]), ['identity_checked' => '1'])
        ->assertSessionHasErrors('handover');
    ccPick($order);
    $this->actingAs($staff)->post(route('warehouse.collections.handover', ['order' => $order->public_id]), ['identity_checked' => '1', 'collector_name' => 'Sam'])
        ->assertSessionHasNoErrors();
    expect(CollectionBooking::query()->sole()->status)->toBe('collected');

    // Only accounts may void; a customer may not see the counter.
    $paymentId = Payment::query()->sole()->public_id;
    $this->actingAs($staff)->post(route('warehouse.collections.cash.void', ['payment' => $paymentId]), ['reason' => 'x'])->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/warehouse/collections')->assertForbidden();
});

// -----------------------------------------------------------------
// §7A.11 no-shows
// -----------------------------------------------------------------

it('suspends at the second no-show, ignores prepaid no-shows, and restarts the count after a lift', function () {
    $user = User::factory()->create();
    $accounts = ccStaff('accounts');
    $suspensions = new PayAtCollectionSuspensions;

    ccCashOrder($user);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 14:00', 'Europe/London'));
    (new CollectionExpiry)->sweep();
    expect($suspensions->noShowCount($user->id, null))->toBe(1)
        ->and(DB::table('pay_at_collection_suspensions')->count())->toBe(0);

    // A prepaid no-show doesn't count.
    $card = Order::factory()->create(['user_id' => $user->id, 'company_id' => null, 'payment_method' => 'card', 'fulfilment_type' => 'collection', 'status' => 'confirmed', 'placed_at' => now()]);
    CollectionBooking::query()->create(['collection_slot_id' => $this->slot->id, 'order_id' => $card->id, 'status' => 'no_show', 'no_show_at' => now()]);
    expect($suspensions->noShowCount($user->id, null))->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00', 'Europe/London'));
    $second = ccSlot('2026-10-13', '09:00', '10:00');
    $total = (int) ccFill($user, $second->id)['total_gross_minor'];
    ccPlace($user, $total, 'cash_at_collection', $second->id)->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00', 'Europe/London'));
    (new CollectionExpiry)->sweep();

    $suspension = DB::table('pay_at_collection_suspensions')->sole();
    expect($suspension->user_id)->toBe($user->id)
        ->and($suspension->reason)->toBe('no_shows')
        ->and($suspension->no_show_count)->toBe(2)
        ->and(DB::table('notification_log')->where('notification_key', 'collection.pay_at_collection_suspended')->count())->toBe(1)
        ->and((new PayAtCollectionEligibility)->refusal($user->id, null, $this->location->id, 100)?->rule)->toBe('suspended');

    $suspensions->lift($suspension->id, $accounts->id, 'Customer called; genuine emergency');
    expect($suspensions->noShowCount($user->id, null))->toBe(0)
        ->and((new PayAtCollectionEligibility)->refusal($user->id, null, $this->location->id, 100))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'collection.pay_at_collection_reinstated')->value('event_family'))->toBe('permission');
});

// -----------------------------------------------------------------
// §7A.6a daily cash report
// -----------------------------------------------------------------

it('reports the day\'s cash per staff member, with voids on their own time, and a CSV', function () {
    $alice = ccStaff('warehouse');
    $alice->forceFill(['first_name' => 'Alice', 'last_name' => 'Ng'])->save();
    $bob = ccStaff('warehouse');
    $bob->forceFill(['first_name' => 'Bob', 'last_name' => 'Ray'])->save();
    $accounts = ccStaff('accounts');
    $one = ccCashOrder();
    $two = ccCashOrder();
    $three = ccCashOrder();

    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30', 'Europe/London'));
    (new CashAtCollection)->record($one->id, $alice->id, $one->total_gross_minor);
    $voided = (new CashAtCollection)->record($two->id, $alice->id, $two->total_gross_minor);
    (new CashAtCollection)->record($three->id, $bob->id, $three->total_gross_minor);
    // 23:30 UK is 22:30Z: still Saturday's report.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 23:30', 'Europe/London'));
    (new CashAtCollection)->void($voided->payment->id, $accounts->id, 'Double entry');

    $report = (new DailyCashReport)->build($this->location->id, CarbonImmutable::parse('2026-10-10'));
    $staff = collect($report['staff'])->keyBy('name');
    $each = $one->total_gross_minor;

    expect($report['recorded_minor'])->toBe(3 * $each)
        ->and($report['voided_minor'])->toBe($each)
        ->and($report['net_minor'])->toBe(2 * $each)
        ->and($staff['Alice Ng']['payments'])->toBe(2)
        ->and($staff['Alice Ng']['voids'])->toBe(1)
        ->and($staff['Bob Ray']['gross_minor'])->toBe($each)
        ->and($report['detail'][0]['kind'])->toBe('void')
        ->and($report['detail'][0]['void_reason'])->toBe('Double entry');

    // Sunday's report holds none of it.
    expect((new DailyCashReport)->build($this->location->id, CarbonImmutable::parse('2026-10-11'))['recorded_minor'])->toBe(0);

    $csv = DailyCashReport::csvRows($report);
    expect($csv[0][0])->toBe('Time (UK)')
        ->and(count($csv))->toBe(5)
        ->and($csv[1][4])->toBe('-10.44');
});

// -----------------------------------------------------------------
// 05.15 §7.2 possession, and cancelling before collection
// -----------------------------------------------------------------

it('runs the 14 days from collected_at for a collection order', function () {
    $staff = ccStaff('warehouse');
    $order = ccCashOrder();
    expect(PossessionDay::for($order))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-10 23:30', 'Europe/London'));
    (new CashAtCollection)->record($order->id, $staff->id, $order->total_gross_minor);
    (new DispatchService)->dispatch(ccPick($order), new DispatchDetails(actorUserId: $staff->id));

    $day = PossessionDay::for($order->fresh());
    expect($day?->basis)->toBe('collected')
        // 22:30Z on the 10th is still the 10th in the UK.
        ->and($day?->date->toDateString())->toBe('2026-10-10')
        ->and($day?->lastDay()->toDateString())->toBe('2026-10-24')
        ->and(CancellationEligibility::for($order->fresh(), CarbonImmutable::parse('2026-10-24 12:00', 'Europe/London'))->available)->toBeTrue()
        ->and(CancellationEligibility::for($order->fresh(), CarbonImmutable::parse('2026-10-25 12:00', 'Europe/London'))->available)->toBeFalse();
});

it('cancels a collection before collection, freeing its place in the slot', function () {
    $order = ccCashOrder();
    expect(DB::table('collection_slots')->where('id', $this->slot->id)->value('booked_count'))->toBe(1);

    (new OrderCancellationService)->cancel($order->id);

    expect(CollectionBooking::query()->sole()->status)->toBe('cancelled')
        ->and(DB::table('collection_slots')->where('id', $this->slot->id)->value('booked_count'))->toBe(0)
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and(Payment::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// §7A.13 the constraints are the backstop
// -----------------------------------------------------------------

it('refuses in the database what the rules refuse', function () {
    $order = ccCashOrder();
    $staff = ccStaff('warehouse');
    (new CashAtCollection)->record($order->id, $staff->id, $order->total_gross_minor);
    $paymentId = (int) Payment::query()->value('id');
    $bookingId = (int) CollectionBooking::query()->value('id');
    $violates = function (Closure $statement, string $constraint): void {
        try {
            DB::transaction($statement);
            $this->fail("Expected {$constraint} to refuse this.");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain($constraint);
        }
    };

    $violates(fn () => DB::table('orders')->where('id', $order->id)->update(['user_id' => null, 'guest_email' => 'someone@example.test']), 'orders_cash_at_collection_chk');
    $violates(fn () => DB::table('orders')->where('id', $order->id)->update(['fulfilment_type' => 'delivery']), 'orders_cash_at_collection_chk');
    $violates(fn () => DB::table('payments')->where('id', $paymentId)->update(['recorded_by_user_id' => null]), 'payments_cash_chk');
    $violates(fn () => DB::table('payments')->insert(['public_id' => (string) Str::ulid(), 'order_id' => $order->id, 'type' => 'payment', 'gateway' => 'cash', 'status' => 'captured', 'amount_minor' => 1, 'recorded_by_user_id' => $staff->id]), 'payments_cash_order_uq');
    $violates(fn () => DB::table('collection_bookings')->where('id', $bookingId)->update(['status' => 'collected']), 'collection_bookings_collected_chk');
    $violates(fn () => DB::table('collection_bookings')->where('id', $bookingId)->update(['status' => 'no_show']), 'collection_bookings_no_show_chk');
    $violates(fn () => DB::table('collection_slots')->where('id', $this->slot->id)->update(['booked_count' => 4]), 'collection_slots_capacity_chk');
    $violates(fn () => DB::table('pay_at_collection_suspensions')->insert(['user_id' => $order->user_id, 'company_id' => 1, 'reason' => 'manual', 'suspended_by_user_id' => $staff->id, 'note' => 'x']), 'pay_at_collection_suspensions_owner_chk');
});

// -----------------------------------------------------------------
// EXPLAIN: the sweep and the no-show count need no new index
// -----------------------------------------------------------------

it('reads the sweep from collection_bookings_payment_due_idx and counts no-shows from the owner\'s orders', function () {
    ccCashOrder();
    DB::statement('ANALYZE collection_bookings');
    DB::statement('SET LOCAL enable_seqscan = off');

    $sweep = DB::select("EXPLAIN (FORMAT JSON) SELECT order_id FROM collection_bookings WHERE status = 'booked' AND payment_due_by IS NOT NULL AND payment_due_by < now() ORDER BY payment_due_by");
    $count = DB::select("EXPLAIN (FORMAT JSON) SELECT count(*) FROM orders JOIN collection_bookings ON collection_bookings.order_id = orders.id WHERE orders.placed_at IS NOT NULL AND orders.company_id IS NULL AND orders.user_id = 1 AND orders.payment_method = 'cash_at_collection' AND collection_bookings.status = 'no_show'");

    expect($sweep[0]->{'QUERY PLAN'})->toContain('collection_bookings_payment_due_idx')
        ->and($count[0]->{'QUERY PLAN'})->toContain('orders_public_user_placed_idx')
        ->and($count[0]->{'QUERY PLAN'})->toContain('collection_bookings_order_uq');
});
