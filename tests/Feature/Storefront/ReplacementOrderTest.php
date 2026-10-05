<?php

use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Ordering\DeliveryAddress;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\OrderCancellationService;
use App\Domain\Returns\CancellationEligibility;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\FaultReports;
use App\Domain\Returns\ReplacementOrders;
use App\Domain\Returns\ReturnInspection;
use App\Domain\Returns\ReturnReceipt;
use App\Domain\Returns\ReturnResolution;
use App\Domain\Warehouse\FulfilmentRules;
use App\Http\Support\OrderPageProps;
use App\Models\DeliveryZone;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCardGateway;

uses(RefreshDatabase::class);

/**
 * 05.4 §14 — replacement orders. A fault reported 40 days after possession
 * (dispatched 2 October 2026, 2 days' transit, possession 7 October), so
 * the customer chooses repair or replacement (§13.4).
 */
beforeEach(function () {
    $this->withoutVite();
    Storage::fake((string) config('filesystems.default'));
    $this->app->instance(PaymentGateway::class, new FakeCardGateway);
    $this->user = User::factory()->create();
    $this->location = Location::factory()->default()->create();
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
});

function replStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

/** A dispatched consumer order: one line, 1 unit at £20.00 net, paid by card, with a delivery address; stock of `$onHand` to replace from. */
function replOrder(int $onHand = 5): Order
{
    $zone = DeliveryZone::factory()->create(['transit_days' => 2]);
    $order = Order::factory()->create([
        'company_id' => null, 'user_id' => test()->user->id, 'status' => 'dispatched', 'delivery_zone_id' => $zone->id, 'delivery_method' => 'parcel',
        'subtotal_net_minor' => 2000, 'shipping_net_minor' => 650, 'shipping_tax_rate_bp' => 2000, 'shipping_tax_minor' => 130,
        'standard_shipping_net_minor' => 650, 'tax_minor' => 530, 'total_gross_minor' => 3180, 'payment_method' => 'card', 'payment_status' => 'paid',
    ]);
    $sku = Sku::factory()->create();
    // landed_cost_e4 is generated (fob + freight + duty + other), so set the parts.
    SkuCost::factory()->for($sku)->create(['fob_e4' => 61234, 'freight_e4' => 0, 'duty_e4' => 0, 'other_e4' => 0, 'valid_from' => now()->subYear()]);
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);
    OrderLine::factory()->forPack($pack, 1)->dispatched()->create(['order_id' => $order->id, 'line_no' => 1, 'line_net_minor' => 2000, 'tax_rate_bp' => 2000, 'line_tax_minor' => 400]);
    OrderAddress::factory()->create(['order_id' => $order->id, 'address_type' => 'delivery', 'contact_name' => 'Ada Lovelace', 'line1' => '1 Analytical Way', 'city' => 'London', 'postcode' => 'N1 1AA', 'country_code' => 'GB']);
    Shipment::factory()->dispatched()->create(['order_id' => $order->id, 'location_id' => test()->location->id, 'dispatched_at' => '2026-10-02 10:00:00+00']);
    Payment::factory()->create(['order_id' => $order->id, 'company_id' => null, 'gateway' => 'stripe', 'gateway_reference' => 'pi_'.Str::random(16), 'status' => 'captured', 'amount_minor' => 3180]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);

    return $order;
}

/** Reported on day 40 with the customer's choice, then approved. */
function replApproved(Order $order, string $choice = 'replacement'): Rma
{
    $rma = (new FaultReports)->report($order->id, [1 => 1], 'faulty', 'It does not switch on.', [], CarbonImmutable::parse('2026-11-16 12:00:00', 'Europe/London'), test()->user->id, $choice);
    (new FaultReports)->approve($rma->id, replStaff('accounts')->id);

    return $rma->fresh();
}

/** Approved, received and inspected: the faulty unit is written off. */
function replInspected(Order $order, string $choice = 'replacement'): Rma
{
    $rma = replApproved($order, $choice);
    (new ReturnReceipt)->receive($rma->id, [1 => 1], replStaff('warehouse')->id);
    (new ReturnInspection)->inspect($rma->id, [1 => ['restock' => 0, 'quarantine' => 0, 'write_off' => 1]], replStaff('warehouse')->id);

    return $rma->fresh();
}

function replReplacement(Rma $rma): Order
{
    return Order::query()->findOrFail($rma->fresh()->replacement_order_id);
}

it('settles a return by creating a zero-value replacement order for the same customer, released to the warehouse at once', function () {
    $original = replOrder();
    $rma = (new ReturnResolution)->resolve(replInspected($original)->id, replStaff('accounts')->id, 'replacement');

    $replacement = replReplacement($rma);
    $line = OrderLine::query()->where('order_id', $replacement->id)->sole();
    $faulty = OrderLine::query()->where('order_id', $original->id)->sole();

    expect($rma->status)->toBe('resolved')
        ->and($rma->resolution_type)->toBe('replacement')
        ->and($rma->refund_gross_minor)->toBe(0)
        ->and($replacement->order_kind)->toBe('replacement')
        ->and($replacement->status)->toBe('confirmed')
        ->and($replacement->payment_status)->toBe('not_required')
        ->and($replacement->payment_method)->toBeNull()
        ->and($replacement->user_id)->toBe($original->user_id)
        ->and($replacement->company_id)->toBeNull()
        ->and($replacement->total_gross_minor)->toBe(0)
        ->and($replacement->shipping_net_minor)->toBe(0)
        ->and($replacement->order_number)->toStartWith('SO-')
        ->and($line->price_source)->toBe('replacement')
        ->and($line->replaces_order_line_id)->toBe($faulty->id)
        ->and($line->sku_id)->toBe($faulty->sku_id)
        ->and($line->base_qty)->toBe(1)
        ->and($line->unit_price_net_e4)->toBe(0)
        ->and($line->line_gross_minor)->toBe(0)
        ->and($line->unit_cost_e4)->toBe(61234)
        ->and(StockAllocation::query()->where('order_line_id', $line->id)->sum('base_qty'))->toBe(1)
        ->and(OrderAddress::query()->where('order_id', $replacement->id)->where('address_type', 'delivery')->value('line1'))->toBe('1 Analytical Way')
        ->and(DB::table('notification_log')->where('notification_key', 'rma.replacement_created')->count())->toBe(1);

    // 05.4 §14.2 R8: payment_method NULL and nothing to pay, so the warehouse may pick it now.
    FulfilmentRules::assertWorkable($replacement);
});

it('never invoices or receipts a replacement (Q-R3, pending the accountant)', function () {
    $rma = (new ReturnResolution)->resolve(replInspected(replOrder())->id, replStaff('accounts')->id, 'replacement');
    $replacement = replReplacement($rma);

    expect(fn () => (new InvoiceService)->issueForOrder($replacement->id))->toThrow(LogicException::class);
    $this->artisan('billing:issue-missing-invoices')->assertSuccessful();
    expect(Invoice::query()->where('order_id', $replacement->id)->count())->toBe(0);
});

it('offers no 14-day cancellation on a replacement, and links it to the order it replaces', function () {
    $original = replOrder();
    $replacement = replReplacement((new ReturnResolution)->resolve(replInspected($original)->id, replStaff('accounts')->id, 'replacement'));

    try {
        (new OrderCancellationService)->cancel($replacement->id);
        $this->fail('A replacement must not be cancellable.');
    } catch (OrderNotCancellableException $e) {
        expect($e->reason)->toBe('replacement_order');
    }

    $props = OrderPageProps::for($replacement->fresh());
    expect($props['kind'])->toBe('replacement')
        ->and($props['can_cancel'])->toBeFalse()
        ->and($props['replaces']['order_number'])->toBe($original->order_number);

    DB::table('orders')->where('id', $replacement->id)->update(['status' => 'dispatched']);
    $eligibility = CancellationEligibility::for($replacement->fresh(), CarbonImmutable::now());
    expect($eligibility->available)->toBeFalse()->and($eligibility->reason)->toBe('replacement_order');
});

it('leaves the return as it was when there is not enough stock', function () {
    $rma = replInspected(replOrder(onHand: 0));

    try {
        (new ReturnResolution)->resolve($rma->id, replStaff('accounts')->id, 'replacement');
        $this->fail('Expected a stock shortfall.');
    } catch (ReturnActionRefusedException $e) {
        expect($e->reason)->toBe('out_of_stock');
    }

    expect($rma->fresh()->status)->toBe('inspected')
        ->and($rma->fresh()->replacement_order_id)->toBeNull()
        ->and(Order::query()->where('order_kind', 'replacement')->count())->toBe(0);
});

it('refuses more than the accepted quantity or lines from another return', function () {
    $rma = replInspected(replOrder());
    $line = RmaLine::query()->where('rma_id', $rma->id)->sole();
    $other = replInspected(replOrder());
    $otherLine = RmaLine::query()->where('rma_id', $other->id)->sole();
    $staff = replStaff('accounts');

    foreach ([[$line->id => 2], [$otherLine->id => 1]] as $quantities) {
        expect(fn () => DB::transaction(fn () => (new ReplacementOrders)->createWithinTransaction(
            Rma::query()->where('id', $rma->id)->lockForUpdate()->firstOrFail(),
            $quantities,
            $staff->id,
        )))->toThrow(ReturnActionRefusedException::class);
    }

    expect(Order::query()->where('order_kind', 'replacement')->count())->toBe(0)
        ->and($rma->fresh()->replacement_order_id)->toBeNull();
});

it('sends a replacement to another address, GB only for a consumer', function () {
    $address = fn (string $country) => new DeliveryAddress('Ada Lovelace', null, null, '9 New Street', null, 'Leeds', null, 'LS1 1AA', $country);
    $rma = replInspected(replOrder());

    expect(fn () => (new ReturnResolution)->resolve($rma->id, replStaff('accounts')->id, 'replacement', replacementAddress: $address('FR')))
        ->toThrow(ReturnActionRefusedException::class);

    $replacement = replReplacement((new ReturnResolution)->resolve($rma->id, replStaff('accounts')->id, 'replacement', replacementAddress: $address('GB')));
    expect(OrderAddress::query()->where('order_id', $replacement->id)->where('address_type', 'delivery')->value('line1'))->toBe('9 New Street');
});

it('lets accounts send an advance replacement with a reason, then reuses it when the return is settled', function () {
    $rma = replApproved(replOrder());
    $url = "/api/v1/warehouse/returns/{$rma->public_id}/advance-replacement";

    $this->actingAs(replStaff('warehouse'))->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson($url, ['reason' => 'Customer needs it for an event on Friday.'])->assertForbidden();
    $accounts = replStaff('accounts');
    $this->actingAs($accounts)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson($url, ['reason' => ''])->assertStatus(422);

    $this->actingAs($accounts)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson($url, ['reason' => 'Customer needs it for an event on Friday.'])
        ->assertOk()
        ->assertJsonPath('data.replacement_order.status', 'confirmed');

    $open = $rma->fresh();
    expect($open->status)->toBe('awaiting_goods')
        ->and($open->replacement_order_id)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'rma.advance_replacement')->where('subject_id', $rma->id)->value('reason'))->toBe('Customer needs it for an event on Friday.');

    $this->actingAs($accounts)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson($url, ['reason' => 'And another one please.'])->assertStatus(422)->assertJsonPath('error.code', 'already_replaced');

    (new ReturnReceipt)->receive($rma->id, [1 => 1], replStaff('warehouse')->id);
    (new ReturnInspection)->inspect($rma->id, [1 => ['restock' => 0, 'quarantine' => 0, 'write_off' => 1]], replStaff('warehouse')->id);
    $settled = (new ReturnResolution)->resolve($rma->id, $accounts->id, 'replacement');

    expect($settled->status)->toBe('resolved')
        ->and($settled->replacement_order_id)->toBe($open->replacement_order_id)
        ->and(Order::query()->where('order_kind', 'replacement')->count())->toBe(1);
});

it('refuses an advance replacement when the customer chose a repair or is within the 30-day reject period', function () {
    $repair = replApproved(replOrder(), 'repair');
    expect(fn () => (new ReplacementOrders)->createAdvance($repair->id, replStaff('accounts')->id, 'Faster for the customer.'))
        ->toThrow(ReturnActionRefusedException::class);

    $early = (new FaultReports)->report(replOrder()->id, [1 => 1], 'faulty', 'Broken.', [], CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/London'), test()->user->id);
    (new FaultReports)->approve($early->id, replStaff('accounts')->id);
    expect(fn () => (new ReplacementOrders)->createAdvance($early->id, replStaff('accounts')->id, 'Faster for the customer.'))
        ->toThrow(ReturnActionRefusedException::class);
    expect(Order::query()->where('order_kind', 'replacement')->count())->toBe(0);
});

it('makes a priced replacement, an unpaid-by-design sale and a second replacement for one return impossible to persist', function () {
    $rma = (new ReturnResolution)->resolve(replInspected(replOrder())->id, replStaff('accounts')->id, 'replacement');
    $replacement = replReplacement($rma);
    $line = OrderLine::query()->where('order_id', $replacement->id)->sole();
    $sale = Order::factory()->create(['company_id' => null, 'user_id' => $this->user->id]);
    $other = replInspected(replOrder());

    $violations = [
        fn () => DB::table('orders')->where('id', $replacement->id)->update(['total_gross_minor' => 100]),
        fn () => DB::table('orders')->where('id', $replacement->id)->update(['payment_status' => 'paid']),
        fn () => DB::table('orders')->where('id', $sale->id)->update(['payment_status' => 'not_required']),
        fn () => DB::table('order_lines')->where('id', $line->id)->update(['unit_price_net_e4' => 10000]),
        fn () => DB::table('order_lines')->where('id', $line->id)->update(['replaces_order_line_id' => null]),
        fn () => DB::table('rmas')->where('id', $other->id)->update(['replacement_order_id' => $replacement->id, 'resolution_type' => 'replacement']),
        fn () => DB::table('rmas')->where('id', $rma->id)->update(['resolution_type' => 'repair']),
    ];
    foreach ($violations as $i => $violation) {
        try {
            DB::transaction($violation);
            $this->fail("Violation {$i} was persisted.");
        } catch (QueryException) {
            // Refused by the database, as it must be.
        }
    }

    expect(DB::table('orders')->where('id', $replacement->id)->value('total_gross_minor'))->toBe(0);
});
