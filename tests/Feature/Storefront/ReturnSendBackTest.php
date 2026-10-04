<?php

use App\Domain\Ordering\GuestOrderLink;
use App\Domain\Returns\ConsumerCancellations;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\ProofOfSending;
use App\Domain\Returns\ReturnReceipt;
use App\Models\Attachment;
use App\Models\Batch;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Rma;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentLineBatch;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S6c — sending a cancelled item back (05.4 §13.5, as amended
 * 2026-10-04): proof of sending sets the deadline at the moment it is
 * uploaded, staff may reject invalid proof (which clears it), the warehouse
 * books the parcel in with no stock movement, the refund deadline is the
 * earlier of proof and receipt, accounts are alerted 3 days before it, and
 * only a return with neither proof nor receipt is swept to not_received.
 *
 * The order is dispatched on Friday 2 October 2026; possession is
 * Wednesday 7 October; the cancellation is made on 8 October, so the goods
 * are due back by 22 October.
 */
beforeEach(function () {
    $this->withoutVite();
    Storage::fake((string) config('filesystems.default'));
    $this->user = User::factory()->create();
});

function at(string $ukDateTime): void
{
    test()->travelTo(CarbonImmutable::parse($ukDateTime, 'Europe/London'));
}

function sendBackRma(array $orderAttributes = []): Rma
{
    $zone = DeliveryZone::factory()->create(['transit_days' => 2]);
    $order = Order::factory()->create($orderAttributes + [
        'company_id' => null, 'user_id' => test()->user->id, 'status' => 'dispatched', 'delivery_zone_id' => $zone->id, 'delivery_method' => 'parcel',
    ]);
    $pack = Pack::factory()->for(Sku::factory()->create())->create(['base_units' => 1]);
    $line = OrderLine::factory()->forPack($pack, 3)->dispatched()->create(['order_id' => $order->id, 'line_no' => 1, 'line_net_minor' => 3000]);
    $shipment = Shipment::factory()->dispatched()->create(['order_id' => $order->id, 'dispatched_at' => '2026-10-02 10:00:00+00']);
    test()->shipmentLine = ShipmentLine::factory()->create(['shipment_id' => $shipment->id, 'order_line_id' => $line->id, 'dispatched_base_qty' => 3]);

    return (new ConsumerCancellations)->request($order->id, [1 => 2], CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'), test()->user->id);
}

function proofFile(): UploadedFile
{
    return UploadedFile::fake()->image('postage.jpg');
}

function staff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create(); // staff without 2FA are refused everywhere
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

it('runs the refund deadline from the earlier of proof and receipt (C10)', function () {
    $rma = sendBackRma();
    expect($rma->refund_due_on)->toBeNull();

    at('2026-10-10 18:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);
    expect($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-24');

    at('2026-10-13 09:00:00');
    (new ReturnReceipt)->receive($rma->id, [1 => 2], staff('warehouse')->id);
    expect($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-24')
        ->and($rma->fresh()->status)->toBe('received');
});

it('runs the deadline from receipt when the goods arrive first, and then needs no proof', function () {
    $rma = sendBackRma();

    at('2026-10-12 09:00:00');
    (new ReturnReceipt)->receive($rma->id, [1 => 2], staff('warehouse')->id);
    expect($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-26');

    expect(fn () => (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id))->toThrow(ReturnActionRefusedException::class);
});

it('takes the upload time as the proof time, and a second upload never makes it later (correction 1)', function () {
    $rma = sendBackRma();

    at('2026-10-10 18:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);
    $sentAt = $rma->fresh()->goods_sent_at;
    expect($sentAt->equalTo(CarbonImmutable::parse('2026-10-10 18:00:00', 'Europe/London')))->toBeTrue();

    at('2026-10-15 09:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);
    expect($rma->fresh()->goods_sent_at->equalTo($sentAt))->toBeTrue()
        ->and($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-24')
        ->and(Attachment::query()->where('attachable_type', 'rma')->where('attachable_id', $rma->id)->count())->toBe(2);
});

it('lets staff reject invalid proof: the date is cleared, the files kept as evidence, the rejection audited (correction 1)', function () {
    $rma = sendBackRma();
    at('2026-10-10 18:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);
    $sentAt = $rma->fresh()->goods_sent_at;
    $firstFile = Attachment::query()->where('attachable_type', 'rma')->where('attachable_id', $rma->id)->sole();

    at('2026-10-11 10:00:00');
    $accounts = staff('accounts');
    (new ProofOfSending)->reject($rma->id, $accounts->id, 'The photo does not show a tracking number.');
    $rejected = $rma->fresh();
    $audit = DB::table('audit_log')->where('action', 'rma.proof_rejected')->sole();

    expect($rejected->goods_sent_at)->toBeNull()
        ->and($rejected->refund_due_on)->toBeNull()
        // Kept, not deleted or soft-deleted: evidence if the refund is disputed.
        ->and(Attachment::query()->where('attachable_type', 'rma')->where('attachable_id', $rma->id)->count())->toBe(1)
        ->and(Attachment::onlyTrashed()->count())->toBe(0)
        ->and(Storage::disk((string) config('filesystems.default'))->exists($firstFile->path))->toBeTrue()
        ->and($audit->event_family)->toBe('rma_disposition')
        ->and((int) $audit->actor_user_id)->toBe($accounts->id)
        ->and($audit->subject_type)->toBe('rma')
        ->and((int) $audit->subject_id)->toBe($rma->id)
        ->and($audit->reason)->toBe('The photo does not show a tracking number.')
        ->and(json_decode($audit->before, true))->toBe(['goods_sent_at' => $sentAt->toIso8601String(), 'last_proof_attachment_id' => $firstFile->id])
        ->and(json_decode($audit->after, true))->toBe(['goods_sent_at' => null])
        ->and(DB::table('notification_log')->where('notification_key', 'rma.proof_rejected')->value('recipient'))->toBe(strtolower($this->user->email));

    at('2026-10-12 08:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);
    expect($rma->fresh()->refund_due_on->toDateString())->toBe('2026-10-26');

    // Staff see the old file marked rejected, and the new one not.
    $proof = $this->actingAs($accounts)->getJson('/api/v1/warehouse/returns/lookup?rma_number='.$rma->rma_number)->assertOk()->json('data.proof');
    expect(array_column($proof, 'rejected'))->toBe([true, false]);
});

it('writes no stock movement at receipt, and recovers the batch when one went out', function () {
    $rma = sendBackRma();
    ShipmentLineBatch::factory()->create(['shipment_line_id' => $this->shipmentLine->id, 'batch_id' => $batch = Batch::factory()->create()->id, 'base_qty' => 3]);
    $movements = StockMovement::query()->count();

    (new ReturnReceipt)->receive($rma->id, [1 => 2], staff('warehouse')->id);

    $line = $rma->lines()->sole();
    expect(StockMovement::query()->count())->toBe($movements)
        ->and($line->received_base_qty)->toBe(2)
        ->and($line->batch_id)->toBe($batch);
    expect(fn () => (new ReturnReceipt)->receive($rma->id, [1 => 2], staff('warehouse')->id))->toThrow(ReturnActionRefusedException::class);
});

it('alerts accounts once, 3 days before a refund falls due', function () {
    $accounts = staff('accounts');
    $rma = sendBackRma();
    at('2026-10-10 18:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id); // due 24 October

    at('2026-10-20 08:00:00');
    $this->artisan('returns:refund-due-alerts')->assertSuccessful();
    expect(DB::table('notification_log')->where('notification_key', 'rma.refund_due_soon')->count())->toBe(0);

    at('2026-10-21 08:00:00');
    $this->artisan('returns:refund-due-alerts')->assertSuccessful();
    at('2026-10-22 08:00:00');
    $this->artisan('returns:refund-due-alerts')->assertSuccessful();
    expect(DB::table('notification_log')->where('notification_key', 'rma.refund_due_soon')->where('user_id', $accounts->id)->count())->toBe(1);
});

it('skips a return with proof but no receipt: the deadline stands (correction 2)', function () {
    $rma = sendBackRma();
    at('2026-10-10 18:00:00');
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);

    at('2026-10-30 08:00:00'); // past the 22 October send-back date, nothing arrived
    $this->artisan('returns:sweep-not-received')->assertSuccessful();

    $rma->refresh();
    expect($rma->status)->toBe('awaiting_goods')
        ->and($rma->refund_due_on->toDateString())->toBe('2026-10-24')
        ->and(DB::table('notification_log')->where('notification_key', 'rma.not_received')->count())->toBe(0);
});

it('closes a return with neither proof nor receipt as not received, with no refund (correction 2)', function () {
    $rma = sendBackRma();

    at('2026-10-22 23:00:00'); // the last send-back day: not yet
    $this->artisan('returns:sweep-not-received')->assertSuccessful();
    expect($rma->fresh()->status)->toBe('awaiting_goods');

    at('2026-10-23 02:30:00');
    $this->artisan('returns:sweep-not-received')->assertSuccessful();
    $rma->refresh();
    expect($rma->status)->toBe('not_received')
        ->and($rma->refund_due_on)->toBeNull()
        ->and(DB::table('notification_log')->where('notification_key', 'rma.not_received')->value('recipient'))->toBe(strtolower($this->user->email));
});

it('never sweeps a pallet return we collect, whose deadline runs from the cancellation', function () {
    $rma = sendBackRma(['delivery_method' => 'pallet', 'return_cost_estimate_gross_minor' => null]);
    expect($rma->return_method)->toBe('collection')
        ->and($rma->refund_due_on->toDateString())->toBe('2026-10-22');

    at('2026-11-01 08:00:00');
    $this->artisan('returns:sweep-not-received')->assertSuccessful();
    expect($rma->fresh()->status)->toBe('awaiting_goods');
    expect(fn () => (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id))->toThrow(ReturnActionRefusedException::class);
});

it('takes proof from the order page, signed in or by guest link', function () {
    $rma = sendBackRma();
    $order = Order::query()->findOrFail($rma->order_id);

    $this->actingAs($this->user)->from(route('orders.confirmation', $order->public_id))
        ->post(route('orders.returns.proof', ['order' => $order->public_id, 'rma' => $rma->public_id]), ['proof' => proofFile()])
        ->assertSessionHas('status');
    expect($rma->fresh()->goods_sent_at)->not->toBeNull();

    $this->actingAs($this->user)->post(route('orders.returns.proof', ['order' => $order->public_id, 'rma' => $rma->public_id]), ['proof' => UploadedFile::fake()->create('notes.exe', 10)])
        ->assertSessionHasErrors('proof');

    $guestRma = sendBackRma(['user_id' => null, 'guest_email' => 'guest@example.com']);
    $guestOrder = Order::query()->findOrFail($guestRma->order_id);
    $url = GuestOrderLink::url($guestOrder);
    $this->from($url)->post("{$url}/returns/{$guestRma->public_id}/proof", ['proof' => proofFile()])->assertSessionHas('status');
    expect($guestRma->fresh()->goods_sent_at)->not->toBeNull();

    // Another order's return cannot be reached through this order.
    $this->actingAs($this->user)->post(route('orders.returns.proof', ['order' => $order->public_id, 'rma' => $guestRma->public_id]), ['proof' => proofFile()])->assertNotFound();
});

it('lets the warehouse book in and accounts reject proof, and no one else', function () {
    $rma = sendBackRma();
    (new ProofOfSending)->upload($rma->id, proofFile(), $this->user->id);
    $warehouse = staff('warehouse');
    $accounts = staff('accounts');
    $receive = fn (User $u) => $this->actingAs($u)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson("/api/v1/warehouse/returns/{$rma->public_id}/receive", ['lines' => [['line_no' => 1, 'received_base_qty' => 2]]]);

    $this->actingAs($warehouse)->postJson("/api/v1/warehouse/returns/{$rma->public_id}/reject-proof", ['reason' => 'Not a postage receipt'])->assertForbidden();
    $this->actingAs($this->user)->getJson('/api/v1/warehouse/returns/lookup?rma_number='.$rma->rma_number)->assertForbidden();
    $receive($accounts)->assertForbidden();

    $this->actingAs($warehouse)->getJson('/api/v1/warehouse/returns/lookup?rma_number='.strtolower($rma->rma_number))
        ->assertOk()->assertJsonPath('data.can_receive', true)->assertJsonPath('data.can_reject_proof', false);
    $proofUrl = $this->actingAs($accounts)->getJson('/api/v1/warehouse/returns/lookup?rma_number='.$rma->rma_number)
        ->assertJsonPath('data.can_reject_proof', true)->json('data.proof.0.url');
    $this->actingAs($accounts)->get($proofUrl)->assertOk();
    $this->actingAs($this->user)->get($proofUrl)->assertForbidden();

    $receive($warehouse)->assertOk()->assertJsonPath('data.status', 'received');
    $this->actingAs($warehouse)->get('/warehouse/returns')->assertOk();
});
