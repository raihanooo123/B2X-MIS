<?php

use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Models\Company;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 06 §8 `/warehouse/shipments*` and the picking and dispatch pages:
 * ShipmentPolicy on every route, 06 §4's envelope for refusals, pick lines
 * addressed by `(line_no, batch_code)`, and 06 §6 idempotency on dispatch.
 */
beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->location = Location::factory()->default()->create(['code' => 'MAIN']);
    $company = Company::factory()->create(['payment_terms' => 'net30']);
    $this->order = Order::factory()->create(['order_number' => 'SO-000777', 'company_id' => $company->id, 'status' => 'confirmed', 'payment_method' => 'on_account', 'payment_status' => 'on_account']);
    $this->sku = Sku::factory()->create(['sku_code' => 'MUG-1', 'barcode_ean' => '5000000000017']);
    $this->pack = Pack::factory()->for($this->sku)->outer(6)->create(['barcode' => '15000000000014']);
    $this->line = OrderLine::factory()->for($this->order)->forPack($this->pack, 2)->create(['line_no' => 1]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 50, 'allocated_base_qty' => 0]);
    (new AllocationService)->allocate(null, 0, [new AllocationLine($this->line->id, $this->sku->id, $this->location->id, null, 12)]);
});

function fulfilStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

function fulfilOpen(User $user): string
{
    return test()->actingAs($user)
        ->postJson('/api/v1/warehouse/shipments', ['order_number' => 'SO-000777'])
        ->assertCreated()
        ->json('data.shipment.id');
}

it('requires sign-in and a warehouse role', function () {
    $this->postJson('/api/v1/warehouse/shipments', ['order_number' => 'SO-000777'])->assertStatus(401);

    foreach ([User::factory()->create(), fulfilStaff('accounts')] as $user) {
        $this->actingAs($user)->postJson('/api/v1/warehouse/shipments', ['order_number' => 'SO-000777'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }
});

it('opens a shipment by order number and returns its pick list in packs, without prices', function () {
    $response = $this->actingAs(fulfilStaff('warehouse'))
        ->postJson('/api/v1/warehouse/shipments', ['order_number' => 'SO-000777'])
        ->assertCreated()
        ->assertHeader('Location')
        ->assertJsonPath('data.order.order_number', 'SO-000777')
        ->assertJsonPath('data.shipment.location_code', 'MAIN')
        ->assertJsonPath('data.lines.0.sku_code', 'MUG-1')
        ->assertJsonPath('data.lines.0.packs', 2)
        ->assertJsonPath('data.lines.0.base_qty', 12)
        ->assertJsonPath('data.lines.0.status', 'allocated')
        ->assertJsonPath('data.complete', false);

    expect($response->json('data.lines.0.barcodes'))->toContain('MUG-1', '5000000000017', '15000000000014');
    expect(json_encode($response->json()))->not->toContain('price')->not->toContain('cost')->not->toContain('_e4');
});

it('names an unknown order', function () {
    $this->actingAs(fulfilStaff('warehouse'))
        ->postJson('/api/v1/warehouse/shipments', ['order_number' => 'SO-404'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'order_not_found');
});

it('confirms a line addressed by line number, then dispatches once with an idempotency key', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/picks", ['line_no' => 1])
        ->assertOk()
        ->assertJsonPath('data.lines.0.status', 'picked')
        ->assertJsonPath('data.complete', true);

    $key = (string) Str::uuid();
    $body = ['carrier' => 'DPD', 'tracking_number' => 'TRK1', 'parcel_count' => 1];

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/dispatch", $body, ['Idempotency-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.shipment.status', 'dispatched')
        ->assertJsonPath('data.result.replayed', false)
        ->assertJsonPath('data.result.order_fully_dispatched', true);

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/dispatch", $body, ['Idempotency-Key' => $key])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(DB::table('stock_movements')->where('movement_type', 'dispatch')->count())->toBe(1);
});

it('requires an Idempotency-Key and a carrier to dispatch a delivery', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);
    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/picks", ['line_no' => 1])->assertOk();

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/dispatch", ['carrier' => 'DPD'])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/dispatch", [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('error.code', 'carrier_required');
});

it('refuses to dispatch an unpicked shipment in the error envelope', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/dispatch", ['carrier' => 'DPD'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unpicked_lines')
        ->assertJsonPath('error.details.0.meta.line_nos', [1]);
});

it('records a short pick entered in packs and loose units', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/short-picks", ['line_no' => 1, 'picked_pack_qty' => 1, 'picked_loose_units' => 2, 'reason' => 'damaged'])
        ->assertOk()
        ->assertJsonPath('data.result.picked_base_qty', 8)
        ->assertJsonPath('data.result.shortfall_base_qty', 4)
        ->assertJsonPath('data.result.backordered_base_qty', 4)
        ->assertJsonPath('data.lines.0.base_qty', 8)
        ->assertJsonPath('data.lines.0.status', 'picked');
});

it('blocks an unallocated serial scan with a 422, not a warning', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);
    StockSerial::factory()->create(['sku_id' => $this->sku->id, 'serial_number' => 'LOOSE-1', 'status' => 'in_stock', 'location_id' => $this->location->id]);

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/serial-scans", ['serial_number' => 'LOOSE-1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'serial_not_allocated')
        ->assertJsonPath('error.details.0.field', 'serial_number');
});

it('answers a line that is not on the pick list', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);

    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/picks", ['line_no' => 9])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'line_not_on_shipment');
});

it('renders the picking page with the queue, and resumes a shipment', function () {
    $user = fulfilStaff('warehouse');

    $this->actingAs($user)->get('/warehouse/pick-list')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Warehouse/PickList', false)
            ->where('pick_list', null)
            ->where('queue.0.order_number', 'SO-000777')
            ->where('queue.0.open_shipment_id', null)
            ->has('short_pick_reasons', 4));

    $id = fulfilOpen($user);

    $this->actingAs($user)->get("/warehouse/pick-list?shipment={$id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Warehouse/PickList', false)
            ->where('pick_list.shipment.id', $id)
            ->where('queue.0.open_shipment_id', $id));
});

it('lists picked shipments on the dispatch page', function () {
    $user = fulfilStaff('warehouse');
    $id = fulfilOpen($user);
    $this->actingAs($user)->postJson("/api/v1/warehouse/shipments/{$id}/picks", ['line_no' => 1])->assertOk();

    $this->actingAs($user)->get('/warehouse/dispatch')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Warehouse/Dispatch', false)
            ->where('queue.0.id', $id)
            ->where('queue.0.order_number', 'SO-000777'));

    expect(Shipment::query()->where('public_id', $id)->value('status'))->toBe('picked');
});

it('keeps the warehouse pages from customers', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)->get('/warehouse/pick-list')->assertForbidden();
    $this->actingAs($customer)->get('/warehouse/dispatch')->assertForbidden();
});
