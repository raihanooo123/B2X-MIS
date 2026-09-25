<?php

use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\Pack;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 06 §8 `/warehouse/receipts*`, `/warehouse/lookup` and the goods-in page:
 * GoodsReceiptPolicy on every route, 06 §4's envelope for GoodsInService's
 * refusals, and 06 §6 idempotency on receiving a line.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->location = Location::factory()->default()->create(['code' => 'MAIN']);
    $this->sku = Sku::factory()->create(['sku_code' => 'GRATER-1', 'barcode_ean' => '5012345678900']);
    $this->each = Pack::factory()->for($this->sku)->create();
    $this->outer = Pack::factory()->for($this->sku)->outer(12)->create(['barcode' => '15012345678907']);
    $this->po = PurchaseOrder::factory()->create(['po_number' => 'PO-000042', 'location_id' => $this->location->id]);
    $this->poLine = PurchaseOrderLine::factory()->for($this->po)->forPack($this->outer, 5)->create(['line_no' => 1]);
});

function staffUser(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

function openPoReceipt(User $user): string
{
    return test()->actingAs($user)
        ->postJson('/api/v1/warehouse/receipts', ['source' => 'purchase_order', 'reference' => 'PO-000042'])
        ->assertCreated()
        ->json('data.id');
}

function receiveBody(array $overrides = []): array
{
    return $overrides + [
        'purchase_order_line' => ['po_number' => 'PO-000042', 'line_no' => 1],
        'sku_id' => test()->sku->public_id,
        'pack_code' => test()->outer->code,
        'pack_qty' => 3,
    ];
}

it('requires sign-in', function () {
    $this->postJson('/api/v1/warehouse/receipts', ['source' => 'manual', 'location_code' => 'MAIN'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('forbids customers and non-warehouse staff from opening a receipt', function (?string $role) {
    $user = $role === null ? User::factory()->create() : staffUser($role);

    $this->actingAs($user)
        ->postJson('/api/v1/warehouse/receipts', ['source' => 'manual', 'location_code' => 'MAIN'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
})->with(['a customer' => [null], 'a rep' => ['rep'], 'purchasing' => ['purchasing']]);

it('opens a PO receipt with its expected lines, in packs, and no cost figures', function () {
    $response = $this->actingAs(staffUser('warehouse'))
        ->postJson('/api/v1/warehouse/receipts', ['source' => 'purchase_order', 'reference' => 'PO-000042'])
        ->assertCreated()
        ->assertHeader('Location')
        ->assertJsonPath('data.source', 'purchase_order')
        ->assertJsonPath('data.reference', 'PO-000042')
        ->assertJsonPath('data.expected_lines.0.po_number', 'PO-000042')
        ->assertJsonPath('data.expected_lines.0.sku.id', $this->sku->public_id)
        ->assertJsonPath('data.expected_lines.0.ordered_pack_qty', 5)
        ->assertJsonPath('data.expected_lines.0.ordered_base_qty', 60)
        ->assertJsonPath('data.expected_lines.0.outstanding_base_qty', 60);

    expect(json_encode($response->json()))->not->toContain('fob')->not->toContain('cost_e4');
});

it('names an unknown PO rather than failing silently', function () {
    $this->actingAs(staffUser('warehouse'))
        ->postJson('/api/v1/warehouse/receipts', ['source' => 'purchase_order', 'reference' => 'PO-999999'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'purchase_order_not_found');
});

it('receives a line: 201, base quantity computed from the pack, idempotent on the key', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);
    $key = (string) Str::uuid();

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody(), ['Idempotency-Key' => $key])
        ->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.base_qty', 36)
        ->assertJsonPath('data.receipt.lines.0.base_qty', 36)
        ->assertJsonPath('data.receipt.expected_lines.0.outstanding_base_qty', 24);

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody(), ['Idempotency-Key' => $key])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(GoodsReceiptLine::query()->count())->toBe(1)
        ->and(GoodsReceiptLine::query()->sole()->client_token)->toBe($key)
        ->and(DB::table('stock_movements')->where('movement_type', 'goods_in')->count())->toBe(1);
});

it('replays from the durable key once the cached response is gone', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);
    $key = (string) Str::uuid();

    $this->actingAs($user)->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody(), ['Idempotency-Key' => $key])->assertCreated();
    cache()->flush();

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody(), ['Idempotency-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.replayed', true);

    expect(DB::table('stock_movements')->where('movement_type', 'goods_in')->count())->toBe(1);
});

it('requires an Idempotency-Key to receive', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');
});

it('returns a refusal in the 06 §4 envelope, against its field', function () {
    $batchSku = Sku::factory()->batchTracked()->create();
    $pack = Pack::factory()->for($batchSku)->create();
    PurchaseOrderLine::factory()->for($this->po)->forPack($pack, 10)->create(['line_no' => 2]);
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", [
            'purchase_order_line' => ['po_number' => 'PO-000042', 'line_no' => 2],
            'sku_id' => $batchSku->public_id,
            'pack_code' => $pack->code,
            'pack_qty' => 10,
            'expires_on' => now()->addYear()->toDateString(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'batch_code_required')
        ->assertJsonPath('error.details.0.field', 'batch_code');
});

it('lets purchasing view a receipt but not book into it', function () {
    $receiptId = openPoReceipt(staffUser('warehouse'));
    $purchasing = staffUser('purchasing');

    $this->actingAs($purchasing)->getJson("/api/v1/warehouse/receipts/{$receiptId}")->assertOk();
    $this->actingAs($purchasing)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody(), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertForbidden();
});

it('closes with a variance decision addressed by PO number and line', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);
    $this->actingAs($user)->postJson("/api/v1/warehouse/receipts/{$receiptId}/lines", receiveBody(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/close", [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'variance_reason_required')
        ->assertJsonPath('error.details.0.field', 'variances.PO-000042.1');

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/close", ['variances' => [['po_number' => 'PO-000042', 'line_no' => 1, 'reason' => 'damaged_in_transit']]])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.expected_lines.0.variance_reason', 'damaged_in_transit');

    expect($this->po->fresh()->status)->toBe('received');
});

it('refuses a variance entry with neither a reason nor remainder_expected', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);

    $this->actingAs($user)
        ->postJson("/api/v1/warehouse/receipts/{$receiptId}/close", ['variances' => [['po_number' => 'PO-000042', 'line_no' => 1, 'remainder_expected' => false]]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('resolves scans: PO number, case barcode with its pack, bin, and unknown codes with candidates', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);
    Product::query()->whereKey($this->sku->product_id)->update(['name' => 'Box grater']);
    Bin::factory()->create(['location_id' => $this->location->id, 'code' => 'A-01-3']);

    $this->actingAs($user)->getJson('/api/v1/warehouse/lookup?code=PO-000042')
        ->assertOk()->assertJsonPath('data.kind', 'purchase_order')->assertJsonPath('data.reference', 'PO-000042');

    $this->actingAs($user)->getJson('/api/v1/warehouse/lookup?code=15012345678907')
        ->assertOk()->assertJsonPath('data.kind', 'sku')->assertJsonPath('data.sku.sku_code', 'GRATER-1')->assertJsonPath('data.pack_code', $this->outer->code);

    $this->actingAs($user)->getJson("/api/v1/warehouse/lookup?code=A-01-3&receipt={$receiptId}")
        ->assertOk()->assertJsonPath('data.kind', 'bin')->assertJsonPath('data.bin_code', 'A-01-3');

    $this->actingAs($user)->getJson('/api/v1/warehouse/lookup?code=grater')
        ->assertOk()->assertJsonPath('data.kind', 'unknown')->assertJsonPath('data.candidates.0.sku_code', 'GRATER-1');
});

it('renders the goods-in page for the warehouse, resuming a receipt', function () {
    $user = staffUser('warehouse');
    $receiptId = openPoReceipt($user);

    $this->actingAs($user)
        ->get("/warehouse/goods-in?receipt={$receiptId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Warehouse/GoodsIn', false)
            ->where('receipt.id', $receiptId)
            ->where('can_receive', true)
            ->has('open_receipts', 1)
            ->has('variance_reasons', 6)
            ->where('default_horizon_days', 3650));
});

it('keeps the goods-in page from customers', function () {
    $this->actingAs(User::factory()->create())->get('/warehouse/goods-in')->assertForbidden();
});

it('shows purchasing the page read-only', function () {
    $this->actingAs(staffUser('purchasing'))
        ->get('/warehouse/goods-in')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Warehouse/GoodsIn', false)->where('can_receive', false));

    expect(GoodsReceipt::query()->count())->toBe(0);
});
