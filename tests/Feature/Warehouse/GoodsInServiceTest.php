<?php

use App\Domain\Warehouse\Events\StockReceived;
use App\Domain\Warehouse\Exceptions\GoodsInRejectedException;
use App\Domain\Warehouse\ExpiryPolicy;
use App\Domain\Warehouse\GoodsInService;
use App\Domain\Warehouse\ReceiptSource;
use App\Domain\Warehouse\ReceiveLine;
use App\Domain\Warehouse\VarianceDecision;
use App\Domain\Warehouse\VarianceReason;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockLevel;
use App\Models\StockSerial;
use App\Models\SystemConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * GoodsInService — 05.5 §4 / §13 "Receipt", 04 §7.1, 02 §23. Every
 * rejection is asserted to write nothing; the ledger, the projection and
 * the PO line are asserted together, since they move in one transaction.
 */
beforeEach(function () {
    $this->location = Location::factory()->default()->create();
    $this->service = new GoodsInService;
});

/**
 * A PO for one SKU: an each and an outer of 12, ordered as 5 outers (60
 * units) at £1.2345 per unit. The NULL-batch row carries the PO's 60
 * units of incoming (02 §23.5) as PO confirmation would.
 *
 * @return array{sku: Sku, each: Pack, outer: Pack, po: PurchaseOrder, line: PurchaseOrderLine, receipt: GoodsReceipt}
 */
function goodsInFixture(?Sku $sku = null, int $orderedOuters = 5, array $poState = []): array
{
    $sku ??= Sku::factory()->create();
    $each = Pack::factory()->for($sku)->create();
    $outer = Pack::factory()->for($sku)->outer(12)->create();
    $po = PurchaseOrder::factory()->create(['location_id' => test()->location->id] + $poState);
    $line = PurchaseOrderLine::factory()->for($po)->forPack($outer, $orderedOuters)->create(['unit_fob_e4' => 12345]);

    StockLevel::factory()->for($sku)->for(test()->location)->create([
        'on_hand_base_qty' => 0,
        'allocated_base_qty' => 0,
        'incoming_base_qty' => $line->base_qty,
    ]);

    $receipt = test()->service->open(ReceiptSource::PurchaseOrder, $po->id, null, null, null);

    return compact('sku', 'each', 'outer', 'po', 'line', 'receipt');
}

function receiveLine(array $f, array $overrides = []): ReceiveLine
{
    $args = $overrides + [
        'clientToken' => (string) Str::uuid(),
        'purchaseOrderLineId' => $f['line']->id,
        'skuId' => $f['sku']->id,
        'packId' => $f['outer']->id,
        'packQty' => 5,
    ];

    return new ReceiveLine(...$args);
}

function levelRow(int $skuId, int $locationId, ?int $batchId = null): ?StockLevel
{
    return StockLevel::identity($skuId, $locationId, $batchId)->first();
}

function assertNothingBooked(GoodsReceipt $receipt): void
{
    expect(GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->count())->toBe(0)
        ->and(DB::table('stock_movements')->where('movement_type', 'goods_in')->count())->toBe(0)
        ->and(SkuCost::query()->count())->toBe(0);
}

function rejectionOf(Closure $call): GoodsInRejectedException
{
    try {
        $call();
    } catch (GoodsInRejectedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a GoodsInRejectedException.');
}

// --- Receiving ------------------------------------------------------------

it('receives packs against a PO line: one goods_in movement, on_hand up, incoming down, PO line progressed', function () {
    Event::fake([StockReceived::class]);
    $f = goodsInFixture();

    $outcome = $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 3]));

    expect($outcome->replayed)->toBeFalse()
        ->and($outcome->line->pack_qty)->toBe(3)
        ->and($outcome->line->pack_base_units)->toBe(12)
        ->and($outcome->line->base_qty)->toBe(36);

    $movement = DB::table('stock_movements')->where('movement_type', 'goods_in')->sole();
    expect($movement->base_qty)->toBe(36)
        ->and($movement->reference_type)->toBe('goods_receipt_line')
        ->and($movement->reference_id)->toBe($outcome->line->id)
        ->and($movement->batch_id)->toBeNull();

    $level = levelRow($f['sku']->id, $this->location->id);
    expect($level->on_hand_base_qty)->toBe(36)
        ->and($level->incoming_base_qty)->toBe(60 - 36)
        ->and($level->last_movement_id)->toBe($movement->id);

    expect($f['line']->fresh()->received_base_qty)->toBe(36);
    Event::assertDispatched(StockReceived::class, fn (StockReceived $e) => $e->baseQty === 36 && $e->goodsReceiptLineId === $outcome->line->id);
});

it('converts every pack level to base units', function (string $pack, int $packQty, int $expected) {
    $f = goodsInFixture();
    $packModel = $pack === 'each' ? $f['each'] : $f['outer'];

    $outcome = $this->service->receive($f['receipt'], receiveLine($f, ['packId' => $packModel->id, 'packQty' => $packQty]));

    expect($outcome->line->base_qty)->toBe($expected)
        ->and(levelRow($f['sku']->id, $this->location->id)->on_hand_base_qty)->toBe($expected);
})->with([
    'outers' => ['outer', 4, 48],
    'eaches (a pack not on the PO line is accepted, 05.5 §12)' => ['each', 7, 7],
]);

it('writes a sku_costs row at e4 scale from the PO line, in GBP', function () {
    $f = goodsInFixture();

    $outcome = $this->service->receive($f['receipt'], receiveLine($f));

    $cost = SkuCost::query()->sole();
    expect($outcome->line->sku_cost_id)->toBe($cost->id)
        ->and($cost->source)->toBe('purchase_order')
        ->and($cost->purchase_order_id)->toBe($f['po']->id)
        ->and($cost->fob_e4)->toBe(12345)
        ->and($cost->landed_cost_e4)->toBe(12345)
        ->and($cost->is_provisional)->toBeFalse()
        ->and(DB::table('stock_movements')->where('movement_type', 'goods_in')->value('unit_cost_e4'))->toBe(12345);
});

it('converts a foreign-currency FOB at the PO rate, half-up, and records the currency and rate', function () {
    $f = goodsInFixture(poState: ['currency' => 'USD', 'fx_rate_e4' => 7853]);

    $this->service->receive($f['receipt'], receiveLine($f));

    // 1.2345 USD × 0.7853 = 0.96945285 GBP → 9694.5285 e4 → 9695 half-up.
    $cost = SkuCost::query()->sole();
    expect($cost->fob_e4)->toBe(9695)
        ->and($cost->currency)->toBe('USD')
        ->and($cost->fx_rate_e4)->toBe(7853);
});

it('marks a container PO cost provisional until apportionment (05.7 §8.5)', function () {
    $container = Container::factory()->create(['location_id' => $this->location->id, 'status' => 'delivered']);
    $f = goodsInFixture(poState: ['container_id' => $container->id]);
    $receipt = $this->service->open(ReceiptSource::Container, null, $container->id, null, null);

    $this->service->receive($receipt, receiveLine($f));

    expect(SkuCost::query()->sole()->is_provisional)->toBeTrue();
});

it('caps the incoming decrement at what was outstanding on an over-receipt', function () {
    $f = goodsInFixture();

    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 6])); // 72 against 60 ordered

    $level = levelRow($f['sku']->id, $this->location->id);
    expect($level->on_hand_base_qty)->toBe(72)
        ->and($level->incoming_base_qty)->toBe(0)
        ->and($f['line']->fresh()->received_base_qty)->toBe(72);
});

it('is additive: two operatives receiving one PO line both persist (05.5 §12, W1)', function () {
    $f = goodsInFixture();
    $second = $this->service->open(ReceiptSource::PurchaseOrder, $f['po']->id, null, null, null);

    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 2]));
    $this->service->receive($second, receiveLine($f, ['packQty' => 3]));

    expect($f['line']->fresh()->received_base_qty)->toBe(60)
        ->and(levelRow($f['sku']->id, $this->location->id)->on_hand_base_qty)->toBe(60)
        ->and(levelRow($f['sku']->id, $this->location->id)->incoming_base_qty)->toBe(0);
});

it('rejects a SKU that is not the PO line\'s', function () {
    $f = goodsInFixture();
    $other = Sku::factory()->create();
    $otherPack = Pack::factory()->for($other)->create();

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['skuId' => $other->id, 'packId' => $otherPack->id])));

    expect($e->errorCode)->toBe('sku_not_on_po_line');
    assertNothingBooked($f['receipt']);
});

it('refuses to book into a closed receipt', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f));
    $this->service->close($f['receipt'], [], null);

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f)));

    expect($e->errorCode)->toBe('receipt_closed')->and($e->status)->toBe(409);
});

// --- Batch and expiry (05.5 §4.3) ------------------------------------------

it('rejects a batch-tracked SKU with no batch code at entry, writing nothing', function (?string $batchCode) {
    $f = goodsInFixture(Sku::factory()->batchTracked()->create());

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => $batchCode, 'expiresOn' => CarbonImmutable::now()->addYear()])));

    expect($e->errorCode)->toBe('batch_code_required')->and($e->field)->toBe('batch_code');
    assertNothingBooked($f['receipt']);
    expect(Batch::query()->count())->toBe(0)
        ->and($f['line']->fresh()->received_base_qty)->toBe(0);
})->with(['missing' => [null], 'blank' => ['   ']]);

it('rejects a requires_expiry SKU with no expiry date', function () {
    $f = goodsInFixture(Sku::factory()->batchTracked()->create());

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'B1'])));

    expect($e->errorCode)->toBe('expiry_required');
    assertNothingBooked($f['receipt']);
});

it('puts on_hand on the batch row and takes incoming off the NULL-batch row (02 §23.5)', function () {
    $f = goodsInFixture(Sku::factory()->batchTracked()->create());
    $expiry = CarbonImmutable::parse('2027-06-30');

    $outcome = $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => ' LOT-42 ', 'expiresOn' => $expiry, 'packQty' => 2]));

    $batch = Batch::query()->sole();
    expect($batch->batch_code)->toBe('LOT-42')
        ->and($batch->expires_on->toDateString())->toBe('2027-06-30')
        ->and($batch->purchase_order_id)->toBe($f['po']->id)
        ->and($batch->sku_cost_id)->toBe($outcome->line->sku_cost_id)
        ->and($batch->unit_cost_e4)->toBe(12345)
        ->and($outcome->line->batch_id)->toBe($batch->id);

    $nullRow = levelRow($f['sku']->id, $this->location->id);
    $batchRow = levelRow($f['sku']->id, $this->location->id, $batch->id);

    // Different rows: a single-row read sees one figure without the other.
    expect($nullRow->incoming_base_qty)->toBe(60 - 24)
        ->and($nullRow->on_hand_base_qty)->toBe(0)
        ->and($batchRow->on_hand_base_qty)->toBe(24)
        ->and($batchRow->incoming_base_qty)->toBe(0);

    expect(DB::table('stock_movements')->where('movement_type', 'goods_in')->value('batch_id'))->toBe($batch->id);
});

it('adds to an existing batch when the expiry matches, and refuses when it does not (05.5 §12)', function () {
    $f = goodsInFixture(Sku::factory()->batchTracked()->create());
    $expiry = CarbonImmutable::parse('2027-06-30');

    $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1', 'expiresOn' => $expiry, 'packQty' => 1]));
    $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1', 'expiresOn' => $expiry, 'packQty' => 1]));

    expect(Batch::query()->count())->toBe(1)
        ->and(levelRow($f['sku']->id, $this->location->id, Batch::query()->sole()->id)->on_hand_base_qty)->toBe(24);

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1', 'expiresOn' => $expiry->addDay(), 'packQty' => 1])));

    expect($e->errorCode)->toBe('batch_expiry_mismatch')
        ->and($e->meta['recorded_expires_on'])->toBe('2027-06-30')
        ->and(GoodsReceiptLine::query()->count())->toBe(2);
});

it('refuses stock into a recalled batch', function () {
    $sku = Sku::factory()->batchTracked()->create();
    $f = goodsInFixture($sku);
    Batch::factory()->for($sku)->recalled('R-1')->create(['batch_code' => 'LOT-9', 'expires_on' => '2027-01-01']);

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-9', 'expiresOn' => CarbonImmutable::parse('2027-01-01')])));

    expect($e->errorCode)->toBe('batch_not_receivable');
    assertNothingBooked($f['receipt']);
});

it('refuses a batch code for an untracked SKU', function () {
    $f = goodsInFixture();

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1'])));

    expect($e->errorCode)->toBe('batch_not_tracked');
});

it('requires confirmation for an expiry in the past or beyond the horizon, then books it', function (int $daysFromToday, string $warning) {
    $f = goodsInFixture(Sku::factory()->batchTracked()->create());
    $date = CarbonImmutable::parse(ExpiryPolicy::receiptDate()->addDays($daysFromToday)->toDateString());

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1', 'expiresOn' => $date])));
    expect($e->errorCode)->toBe('expiry_confirmation_required')
        ->and($e->meta['warnings'])->toBe([$warning]);
    assertNothingBooked($f['receipt']);

    $outcome = $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1', 'expiresOn' => $date, 'expiryWarningsConfirmed' => true]));
    expect($outcome->line->base_qty)->toBe(60);
})->with([
    'past' => [-1, ExpiryPolicy::WARNING_PAST],
    'beyond the default ten-year horizon' => [3651, ExpiryPolicy::WARNING_BEYOND_HORIZON],
]);

it('takes the sanity horizon from location configuration', function () {
    SystemConfiguration::factory()->create([
        'config_key' => ExpiryPolicy::HORIZON_KEY,
        'scope' => 'location',
        'location_id' => $this->location->id,
        'value_int' => 30,
    ]);
    $f = goodsInFixture(Sku::factory()->batchTracked()->create());

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['batchCode' => 'LOT-1', 'expiresOn' => ExpiryPolicy::receiptDate()->addDays(31)])));

    expect($e->errorCode)->toBe('expiry_confirmation_required');
});

it('pre-fills expiry from shelf_life_days for batch-tracked SKUs only', function () {
    $policy = new ExpiryPolicy;
    $date = CarbonImmutable::parse('2026-09-25', ExpiryPolicy::TIMEZONE);

    expect($policy->prefill(Sku::factory()->batchTracked()->create(['shelf_life_days' => 90]), $date)?->toDateString())->toBe('2026-12-24')
        ->and($policy->prefill(Sku::factory()->batchTracked()->create(['shelf_life_days' => null]), $date))->toBeNull()
        ->and($policy->prefill(Sku::factory()->create(), $date))->toBeNull();
});

it("dates a receipt by the warehouse's day, Europe/London", function () {
    expect(ExpiryPolicy::receiptDate(CarbonImmutable::parse('2026-09-25T23:30:00Z'))->toDateString())->toBe('2026-09-26');
});

// --- Serials (05.5 §4.3, 02 §7.6) ------------------------------------------

function serialFixture(): array
{
    $sku = Sku::factory()->create(['tracking_mode' => 'serial']);

    return goodsInFixture($sku, 1); // 1 outer = 12 units
}

/** @return list<string> */
function serialNumbers(int $count, string $prefix = 'SN'): array
{
    return array_map(fn (int $i) => $prefix.str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(1, $count));
}

it('receives one serial per unit, each in_stock and pointing at the receipt movement', function () {
    $f = serialFixture();

    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 1, 'serials' => serialNumbers(12)]));

    $movementId = DB::table('stock_movements')->where('movement_type', 'goods_in')->value('id');
    expect(StockSerial::query()->where('status', 'in_stock')->where('received_movement_id', $movementId)->count())->toBe(12);
});

it('rejects a serial count that does not match the units', function () {
    $f = serialFixture();

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 1, 'serials' => serialNumbers(11)])));

    expect($e->errorCode)->toBe('serial_count_mismatch')
        ->and($e->meta)->toBe(['expected' => 12, 'captured' => 11]);
    assertNothingBooked($f['receipt']);
});

it('rejects a duplicate serial, naming the receipt it was first seen on', function () {
    $f = serialFixture();
    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 1, 'serials' => serialNumbers(12)]));

    $again = array_merge(serialNumbers(11, 'NEW'), ['SN0005']);
    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 1, 'serials' => $again])));

    expect($e->errorCode)->toBe('serial_already_received')
        ->and($e->meta['serial_number'])->toBe('SN0005')
        ->and($e->meta['prior_receipt_id'])->toBe($f['receipt']->public_id)
        ->and($e->getMessage())->toContain($f['receipt']->public_id);
    expect(StockSerial::query()->count())->toBe(12)
        ->and(GoodsReceiptLine::query()->count())->toBe(1);
});

it('transitions a pre-registered expected serial to in_stock without duplicating it', function () {
    $f = serialFixture();
    StockSerial::factory()->expected()->create(['sku_id' => $f['sku']->id, 'serial_number' => 'SN0003']);

    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 1, 'serials' => serialNumbers(12)]));

    $serial = StockSerial::query()->where('serial_number', 'SN0003')->sole();
    expect($serial->status)->toBe('in_stock')
        ->and($serial->location_id)->toBe($this->location->id)
        ->and($serial->received_movement_id)->not->toBeNull()
        ->and(StockSerial::query()->count())->toBe(12);
});

it('rejects the same serial twice in one entry', function () {
    $f = serialFixture();
    $serials = serialNumbers(12);
    $serials[11] = 'SN0001';

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 1, 'serials' => $serials])));

    expect($e->errorCode)->toBe('serial_duplicated_in_entry')->and($e->meta['duplicates'])->toBe(['SN0001']);
});

// --- Idempotency (05.5 §10, 02 §23.2) --------------------------------------

it('writes one movement set for a retried receipt line, and replays the original', function () {
    Event::fake([StockReceived::class]);
    $f = goodsInFixture();
    $line = receiveLine($f, ['clientToken' => 'token-1', 'packQty' => 2]);

    $first = $this->service->receive($f['receipt'], $line);
    $retry = $this->service->receive($f['receipt'], $line);

    expect($retry->replayed)->toBeTrue()
        ->and($retry->line->id)->toBe($first->line->id)
        ->and(GoodsReceiptLine::query()->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('movement_type', 'goods_in')->count())->toBe(1)
        ->and(SkuCost::query()->count())->toBe(1)
        ->and(levelRow($f['sku']->id, $this->location->id)->on_hand_base_qty)->toBe(24)
        ->and($f['line']->fresh()->received_base_qty)->toBe(24);
    Event::assertDispatchedTimes(StockReceived::class, 1);
});

it('refuses a key reused for a different entry', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f, ['clientToken' => 'token-1', 'packQty' => 2]));

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['clientToken' => 'token-1', 'packQty' => 3])));

    expect($e->errorCode)->toBe('idempotency_key_reuse')->and($e->status)->toBe(409)
        ->and(GoodsReceiptLine::query()->count())->toBe(1);
});

it('holds the key durably in goods_receipt_lines_idempotency_uq, NULLs not distinct', function () {
    $receipt = GoodsReceipt::factory()->create(['location_id' => $this->location->id]);
    $line = GoodsReceiptLine::factory()->create(['goods_receipt_id' => $receipt->id, 'client_token' => 'dup']);

    expect(fn () => GoodsReceiptLine::factory()->create([
        'goods_receipt_id' => $receipt->id,
        'client_token' => 'dup',
        'sku_id' => $line->sku_id,
        'pack_id' => $line->pack_id,
    ]))->toThrow(QueryException::class);
});

// --- Manual receipts (05.5 §4.1, §4.5) -------------------------------------

it('books a manual receipt without cost, leaving it flagged for purchasing', function () {
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create();
    $bin = Bin::factory()->create(['location_id' => $this->location->id]);
    $receipt = $this->service->open(ReceiptSource::Manual, null, null, $this->location->id, null);

    $outcome = $this->service->receive($receipt, new ReceiveLine((string) Str::uuid(), null, $sku->id, $pack->id, 10, binId: $bin->id));

    expect($outcome->line->sku_cost_id)->toBeNull()
        ->and($outcome->line->bin_id)->toBe($bin->id)
        ->and(SkuCost::query()->count())->toBe(0)
        ->and(levelRow($sku->id, $this->location->id)->on_hand_base_qty)->toBe(10)
        ->and(levelRow($sku->id, $this->location->id)->incoming_base_qty)->toBe(0);
});

it('writes a manual cost when one is entered', function () {
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create();
    $receipt = $this->service->open(ReceiptSource::Manual, null, null, $this->location->id, null);

    $this->service->receive($receipt, new ReceiveLine((string) Str::uuid(), null, $sku->id, $pack->id, 10, unitCostE4: 9212));

    $cost = SkuCost::query()->sole();
    expect($cost->source)->toBe('manual')->and($cost->fob_e4)->toBe(9212)->and($cost->currency)->toBe('GBP');
});

it('rejects a bin at another location', function () {
    $f = goodsInFixture();
    $elsewhere = Bin::factory()->create(['location_id' => Location::factory()->create()->id]);

    $e = rejectionOf(fn () => $this->service->receive($f['receipt'], receiveLine($f, ['binId' => $elsewhere->id])));

    expect($e->errorCode)->toBe('bin_not_at_location');
});

it('refuses to open a receipt against a draft PO', function () {
    $po = PurchaseOrder::factory()->draft()->create(['location_id' => $this->location->id]);

    $e = rejectionOf(fn () => $this->service->open(ReceiptSource::PurchaseOrder, $po->id, null, null, null));

    expect($e->errorCode)->toBe('purchase_order_not_receivable');
});

// --- Closing, with variance (05.5 §4.4, 02 §23.3) --------------------------

it('closes a fully received PO line with no variance and marks the PO received', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f));

    $closer = User::factory()->create();

    $closed = $this->service->close($f['receipt'], [], $closer->id);

    expect($closed->status)->toBe('closed')
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->closed_by_user_id)->toBe($closer->id)
        ->and($f['po']->fresh()->status)->toBe('received')
        ->and($f['line']->fresh()->variance_reason)->toBeNull();
});

it('will not close a short line without a decision, and says which', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 4])); // 48 of 60

    $e = rejectionOf(fn () => $this->service->close($f['receipt'], [], null));

    expect($e->errorCode)->toBe('variance_reason_required')
        ->and($e->details)->toHaveCount(1)
        ->and($e->details[0]['code'])->toBe('short_receipt_decision_required')
        ->and($e->details[0]['meta'])->toMatchArray(['line_no' => $f['line']->line_no, 'ordered_base_qty' => 60, 'received_base_qty' => 48])
        ->and($f['receipt']->fresh()->status)->toBe('open');
});

it('keeps the PO part_received when the remainder is expected', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 4]));

    $this->service->close($f['receipt'], [VarianceDecision::remainderExpected($f['line']->id)], null);

    expect($f['po']->fresh()->status)->toBe('part_received')
        ->and($f['line']->fresh()->variance_reason)->toBeNull()
        ->and(levelRow($f['sku']->id, $this->location->id)->incoming_base_qty)->toBe(12);
});

it('records a short reason, completes the PO, and stops counting the shortfall as incoming', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 4]));

    $this->service->close($f['receipt'], [new VarianceDecision($f['line']->id, VarianceReason::ShortShipped)], null);

    expect($f['line']->fresh()->variance_reason)->toBe('short_shipped')
        ->and($f['po']->fresh()->status)->toBe('received')
        ->and($f['po']->fresh()->received_at)->not->toBeNull()
        ->and(levelRow($f['sku']->id, $this->location->id)->incoming_base_qty)->toBe(0);
});

it('requires a reason, not "remainder expected", for an over-receipt', function () {
    $f = goodsInFixture();
    $this->service->receive($f['receipt'], receiveLine($f, ['packQty' => 6]));

    $e = rejectionOf(fn () => $this->service->close($f['receipt'], [VarianceDecision::remainderExpected($f['line']->id)], null));
    expect($e->details[0]['code'])->toBe('over_receipt_reason_required');

    $this->service->close($f['receipt'], [new VarianceDecision($f['line']->id, VarianceReason::OverShipped)], null);
    expect($f['line']->fresh()->variance_reason)->toBe('over_shipped');
});

it('refuses a variance for a PO line the receipt did not touch', function () {
    $f = goodsInFixture();
    $untouched = PurchaseOrderLine::factory()->for($f['po'])->create(['line_no' => 2]);

    $e = rejectionOf(fn () => $this->service->close($f['receipt'], [new VarianceDecision($untouched->id, VarianceReason::Other)], null));

    expect($e->errorCode)->toBe('variance_line_not_on_receipt');
});

it('treats closing a closed receipt as a no-op', function () {
    $f = goodsInFixture();
    [$first, $second] = User::factory()->count(2)->create()->all();
    $this->service->close($f['receipt'], [], $first->id);
    $closedAt = $f['receipt']->fresh()->closed_at;

    $again = $this->service->close($f['receipt'], [], $second->id);

    expect($again->closed_at->equalTo($closedAt))->toBeTrue()->and($again->closed_by_user_id)->toBe($first->id);
});

it('keeps receipt lines append-only', function () {
    $f = goodsInFixture();
    $outcome = $this->service->receive($f['receipt'], receiveLine($f));

    expect(fn () => $outcome->line->update(['pack_qty' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $outcome->line->delete())->toThrow(LogicException::class);
});
