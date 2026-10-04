<?php

use App\Domain\Billing\PaymentGateway;
use App\Domain\Notifications\Notices\RmaApproved;
use App\Domain\Notifications\Recipient;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\FaultReports;
use App\Domain\Returns\ReturnInspection;
use App\Domain\Returns\ReturnReceipt;
use App\Domain\Returns\ReturnResolution;
use App\Models\Attachment;
use App\Models\CreditNote;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\Rma;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCardGateway;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S6e — faulty goods (05.4 §13.4) and staff entry (§13.3):
 * "Report a problem" is never blocked by the 14-day window; within 30 days
 * of possession a full refund including the whole delivery, with no
 * deduction; after 30 days repair or replacement first (C11); a
 * cancellation staff record keeps the time the customer told us.
 *
 * The order is dispatched on 2 October 2026 with 2 days' transit, so
 * possession is 7 October: the 14-day cancellation window ends on 21
 * October and the 30-day right to reject on 6 November.
 */
beforeEach(function () {
    $this->withoutVite();
    Storage::fake((string) config('filesystems.default'));
    $this->gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);
    $this->user = User::factory()->create();
    $this->location = Location::factory()->default()->create();
});

function faultStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

/** A consumer order, one line of 1 unit at £20.00 net, next-day delivery £12.00 (standard £6.50), paid by card. */
function faultOrder(?Sku $sku = null): Order
{
    $zone = DeliveryZone::factory()->create(['transit_days' => 2]);
    $order = Order::factory()->create([
        'company_id' => null, 'user_id' => test()->user->id, 'status' => 'dispatched', 'delivery_zone_id' => $zone->id, 'delivery_method' => 'parcel',
        'subtotal_net_minor' => 2000, 'shipping_net_minor' => 1200, 'shipping_tax_rate_bp' => 2000, 'shipping_tax_minor' => 240,
        'standard_shipping_net_minor' => 650, 'tax_minor' => 640, 'total_gross_minor' => 3840, 'payment_method' => 'card', 'payment_status' => 'paid',
    ]);
    $pack = Pack::factory()->for($sku ?? Sku::factory()->create())->create(['base_units' => 1]);
    OrderLine::factory()->forPack($pack, 1)->dispatched()->create(['order_id' => $order->id, 'line_no' => 1, 'line_net_minor' => 2000, 'tax_rate_bp' => 2000, 'line_tax_minor' => 400]);
    Shipment::factory()->dispatched()->create(['order_id' => $order->id, 'location_id' => test()->location->id, 'dispatched_at' => '2026-10-02 10:00:00+00']);
    Payment::factory()->create(['order_id' => $order->id, 'company_id' => null, 'gateway' => 'stripe', 'gateway_reference' => 'pi_'.Str::random(16), 'status' => 'captured', 'amount_minor' => 3840]);

    return $order;
}

function faultReport(Order $order, string $ukDateTime, string $reason = 'faulty', ?string $choice = null): Rma
{
    return (new FaultReports)->report($order->id, [1 => 1], $reason, 'It does not switch on.', [], CarbonImmutable::parse($ukDateTime, 'Europe/London'), test()->user->id, $choice);
}

/** Approve, receive and inspect a report: the goods arrive and are written off. */
function faultReceived(Rma $rma): Rma
{
    (new FaultReports)->approve($rma->id, faultStaff('accounts')->id);
    (new ReturnReceipt)->receive($rma->id, [1 => 1], faultStaff('warehouse')->id);
    (new ReturnInspection)->inspect($rma->id, [1 => ['restock' => 0, 'quarantine' => 0, 'write_off' => 1]], faultStaff('warehouse')->id);

    return $rma->fresh();
}

it('refunds faulty goods in full within 30 days, including the whole delivery, with no deduction', function () {
    $rma = faultReport(faultOrder(), '2026-11-06 18:00:00'); // day 30 after possession
    expect(FaultReports::withinRejectPeriod($rma))->toBeTrue()
        ->and($rma->status)->toBe('requested');

    $settled = (new ReturnResolution)->resolve(faultReceived($rma)->id, faultStaff('accounts')->id);

    expect($settled->resolution_type)->toBe('credit_note')
        ->and($settled->refund_net_minor)->toBe(2000)
        ->and($settled->refund_tax_minor)->toBe(400)
        // The whole £12.00 next-day delivery, not the £6.50 standard: our fault, not a change of mind.
        ->and($settled->delivery_refund_net_minor)->toBe(1200)
        ->and($settled->delivery_refund_tax_minor)->toBe(240)
        ->and($settled->refund_gross_minor)->toBe(3840)
        ->and($this->gateway->refunds[0]['amount_minor'])->toBe(3840)
        ->and(CreditNote::query()->sole()->total_gross_minor)->toBe(3840);
});

it('never takes a deduction for diminished value on faulty goods', function () {
    $rma = faultReport(faultOrder(), '2026-10-20 12:00:00');
    (new FaultReports)->approve($rma->id, faultStaff('accounts')->id);
    (new ReturnReceipt)->receive($rma->id, [1 => 1], faultStaff('warehouse')->id);

    try {
        (new ReturnInspection)->inspect($rma->id, [1 => ['restock' => 0, 'quarantine' => 1, 'write_off' => 0, 'diminished_value_minor' => 500, 'diminished_value_reason' => 'Scratched']], faultStaff('warehouse')->id);
        $this->fail('Expected the deduction to be refused.');
    } catch (ReturnActionRefusedException $e) {
        expect($e->reason)->toBe('diminished_not_allowed');
    }
});

it('accepts a fault reported on day 40, offering repair or replacement first (C11)', function () {
    $rma = faultReport(faultOrder(), '2026-11-16 12:00:00', choice: 'repair'); // day 40, long after the 14-day window
    expect($rma->status)->toBe('requested')
        ->and(FaultReports::withinRejectPeriod($rma))->toBeFalse();

    $received = faultReceived($rma);
    expect(fn () => (new ReturnResolution)->resolve($received->id, faultStaff('accounts')->id, 'credit_note'))->toThrow(ReturnActionRefusedException::class);

    $repaired = (new ReturnResolution)->resolve($received->id, faultStaff('accounts')->id);
    expect($repaired->status)->toBe('resolved')
        ->and($repaired->resolution_type)->toBe('repair')
        ->and($repaired->refund_gross_minor)->toBe(0)
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Payment::query()->where('type', 'refund')->count())->toBe(0);
});

it('takes a report for bespoke goods too: a fault is never excluded', function () {
    $rma = faultReport(faultOrder(Sku::factory()->nonRefundable('bespoke')->create()), '2026-10-25 12:00:00');

    expect($rma->status)->toBe('requested')
        ->and(DB::table('notification_log')->where('notification_key', 'rma.requested')->count())->toBe(0); // no accounts user yet
});

it('takes "Report a problem" from the order page with photos, and tells the handler', function () {
    $accounts = faultStaff('accounts');
    $order = faultOrder();

    $this->actingAs($this->user)->from(route('orders.confirmation', $order->public_id))
        ->post(route('orders.problems', $order->public_id), [
            'reason' => 'damaged', 'detail' => 'The glass arrived cracked.',
            'lines' => [['line_no' => 1, 'pack_qty' => 1]],
            'photos' => [UploadedFile::fake()->image('crack.jpg')],
        ])->assertSessionHas('status');

    $rma = Rma::query()->sole();
    expect($rma->return_reason)->toBe('damaged')
        ->and($rma->reason_detail)->toBe('The glass arrived cracked.')
        ->and($rma->carriage_payer)->toBe('us')
        ->and(Attachment::query()->where('attachable_type', 'rma')->where('attachable_id', $rma->id)->count())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'rma.requested')->where('user_id', $accounts->id)->exists())->toBeTrue();
});

it('lets accounts approve a report, collecting at our cost, or reject it with a reason', function () {
    $order = faultOrder();
    $approved = faultReport($order, '2026-10-20 12:00:00');

    $this->actingAs(faultStaff('warehouse'))->postJson("/api/v1/warehouse/returns/{$approved->public_id}/approve")->assertForbidden();
    $this->actingAs(faultStaff('accounts'))->postJson("/api/v1/warehouse/returns/{$approved->public_id}/approve")
        ->assertOk()->assertJsonPath('data.status', 'awaiting_goods');
    expect($approved->fresh()->return_method)->toBe('collection')
        ->and(implode(' ', (new RmaApproved($approved->id))->content(Recipient::user($this->user))->paragraphs))->toContain('collect them, at our cost');

    $rejected = (new FaultReports)->report(faultOrder()->id, [1 => 1], 'not_as_described', 'Colour is wrong.', [], CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/London'), $this->user->id);
    $this->actingAs(faultStaff('accounts'))->postJson("/api/v1/warehouse/returns/{$rejected->public_id}/reject", ['reason' => 'The colour matches the product photo.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(DB::table('notification_log')->where('notification_key', 'rma.rejected')->value('recipient'))->toBe(strtolower($this->user->email));
});

it('keeps the time the customer told us when staff record their cancellation', function () {
    $order = faultOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00', 'Europe/London')); // after the 21 October window

    $this->actingAs(faultStaff('accounts'))->postJson('/api/v1/warehouse/returns/cancellations', [
        'order_number' => $order->order_number,
        'notified_at' => '2026-10-20T16:30',
        'lines' => [['line_no' => 1, 'pack_qty' => 1]],
    ])->assertCreated()->assertJsonPath('data.status', 'awaiting_goods');

    $rma = Rma::query()->sole();
    expect($rma->cancellation_notified_at->equalTo(CarbonImmutable::parse('2026-10-20 16:30:00', 'Europe/London')))->toBeTrue()
        ->and($rma->return_reason)->toBe('consumer_cancellation')
        ->and($rma->requested_by_user_id)->toBeNull()
        ->and($rma->handled_by_user_id)->not->toBeNull()
        ->and($rma->return_by_date->toDateString())->toBe('2026-11-03');

    // A time the customer told us cannot be in the future, and a late notification is still refused.
    $this->actingAs(faultStaff('accounts'))->postJson('/api/v1/warehouse/returns/cancellations', ['order_number' => faultOrder()->order_number, 'notified_at' => '2026-10-26T10:00', 'lines' => [['line_no' => 1, 'pack_qty' => 1]]])
        ->assertStatus(422)->assertJsonPath('error.code', 'notified_in_future');
    $this->actingAs(faultStaff('accounts'))->postJson('/api/v1/warehouse/returns/cancellations', ['order_number' => faultOrder()->order_number, 'notified_at' => '2026-10-22T10:00', 'lines' => [['line_no' => 1, 'pack_qty' => 1]]])
        ->assertStatus(422)->assertJsonPath('error.code', 'window_closed');
    $this->actingAs(faultStaff('warehouse'))->postJson('/api/v1/warehouse/returns/cancellations', ['order_number' => $order->order_number, 'notified_at' => '2026-10-20T16:30', 'lines' => [['line_no' => 1, 'pack_qty' => 1]]])
        ->assertForbidden();
    $this->travelBack();
});

it('requires the customer choice after day 30 and retains it through a reasoned staff override', function (string $overrideBasis) {
    $order = faultOrder();
    expect(fn () => faultReport($order, '2026-11-07 00:00:00'))->toThrow(CancellationRequestRejectedException::class);
    expect(Rma::query()->count())->toBe(0);
    $rma = faultReport($order, '2026-11-07 00:00:00', choice: 'replacement');
    expect($rma->resolution_type)->toBe('replacement');
    $received = faultReceived($rma);
    $accounts = faultStaff('accounts');
    foreach ([[null, null], ['impossible', ''], ['convenient', 'Easier for us']] as [$basis, $reason]) {
        expect(fn () => (new ReturnResolution)->resolve($received->id, $accounts->id, 'repair', $basis, null, $reason))
            ->toThrow(ReturnActionRefusedException::class);
    }
    $settled = (new ReturnResolution)->resolve($received->id, $accounts->id, 'repair', $overrideBasis, null, 'This model is discontinued; no replacement is available.');
    $record = json_decode($settled->internal_note, true);
    expect($settled->resolution_type)->toBe('repair')
        ->and($record['customer_choice'])->toBe('replacement')
        ->and($record['decisions'][0]['override_basis'])->toBe($overrideBasis)
        ->and($record['decisions'][0]['staff_user_id'])->toBe($accounts->id)
        ->and(Payment::query()->where('type', 'refund')->count())->toBe(0);
})->with(['impossible', 'disproportionate']);

it('refunds after day 30 only with a recorded failed or refused remedy and never twice', function (string $outcome) {
    $rma = faultReceived(faultReport(faultOrder(), '2026-11-16 12:00:00', choice: 'replacement'));
    $accounts = faultStaff('accounts');
    expect(fn () => (new ReturnResolution)->resolve($rma->id, $accounts->id, 'credit_note'))
        ->toThrow(ReturnActionRefusedException::class);
    expect(fn () => (new ReturnResolution)->resolve($rma->id, $accounts->id, 'credit_note', null, $outcome, '  '))
        ->toThrow(ReturnActionRefusedException::class);
    (new ReturnResolution)->resolve($rma->id, $accounts->id, 'replacement');
    $settled = (new ReturnResolution)->resolve($rma->id, $accounts->id, 'credit_note', null, $outcome, 'Replacement could not remedy the reported fault.');
    expect($settled->refund_gross_minor)->toBe(2400)
        ->and(json_decode($settled->internal_note, true)['decisions'][0]['remedy_outcome'])->toBe($outcome)
        ->and(CreditNote::query()->count())->toBe(1)
        ->and(Payment::query()->where('type', 'refund')->count())->toBe(1);
    expect(fn () => (new ReturnResolution)->resolve($rma->id, $accounts->id, 'credit_note', null, $outcome, 'Again'))
        ->toThrow(ReturnActionRefusedException::class);
    expect(CreditNote::query()->count())->toBe(1)
        ->and($this->gateway->refunds)->toHaveCount(1);
})->with(['failed', 'refused']);

it('keeps the UK approval plus 14-day fault deadline through receipt and alerts accounts once', function () {
    $accounts = faultStaff('accounts');
    $rma = faultReport(faultOrder(), '2026-10-08 12:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-09 23:30:00', 'UTC')); // UK date is 10 October
    (new FaultReports)->approve($rma->id, $accounts->id);
    expect($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-24');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/London'));
    $this->artisan('returns:refund-due-alerts')->assertSuccessful();
    expect(DB::table('notification_log')->where('notification_key', 'rma.refund_due_soon')->count())->toBe(0);
    (new ReturnReceipt)->receive($rma->id, [1 => 1], faultStaff('warehouse')->id);
    expect($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-24');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'Europe/London'));
    $this->artisan('returns:refund-due-alerts')->assertSuccessful();
    $this->artisan('returns:refund-due-alerts')->assertSuccessful();
    expect(DB::table('notification_log')->where('notification_key', 'rma.refund_due_soon')->where('user_id', $accounts->id)->count())->toBe(1);
    $this->travelBack();
});

it('records the customer replacement choice from the order page', function () {
    $order = faultOrder();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'Europe/London'));
    $this->actingAs($this->user)->post(route('orders.problems', $order->public_id), [
        'reason' => 'faulty', 'detail' => 'It does not switch on.', 'customer_choice' => 'replacement',
        'lines' => [['line_no' => 1, 'pack_qty' => 1]],
    ])->assertSessionHasNoErrors()->assertSessionHas('status');
    expect(Rma::query()->sole()->resolution_type)->toBe('replacement');
    $this->travelBack();
});
