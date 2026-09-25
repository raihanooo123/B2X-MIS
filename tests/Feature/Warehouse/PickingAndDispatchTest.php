<?php

use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Notifications\Notices\ShipmentDispatched as ShipmentDispatchedNotice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Warehouse\BatchSubstitutionService;
use App\Domain\Warehouse\DispatchDetails;
use App\Domain\Warehouse\DispatchService;
use App\Domain\Warehouse\Events\BatchSubstituted;
use App\Domain\Warehouse\Events\ShipmentDispatched;
use App\Domain\Warehouse\Events\ShortPickRecorded;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Domain\Warehouse\PickConfirmationService;
use App\Domain\Warehouse\PickListGenerator;
use App\Domain\Warehouse\ShortPickReason;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\Company;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentLineBatch;
use App\Models\ShipmentLineSerial;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\StockSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Picking and dispatch — 05.5 §5, §7, §13 "Picking" and "Dispatch";
 * 04 §6.2, §7.2. Every refusal is asserted to write nothing; the ledger,
 * the projection, the allocation and the order line are asserted
 * together, since each action moves them in one transaction.
 */
beforeEach(function () {
    Queue::fake();
    $this->location = Location::factory()->default()->create();
    $this->generator = new PickListGenerator;
    $this->picking = new PickConfirmationService;
    $this->dispatcher = new DispatchService;
});

/** An on-account order at the default location. */
function fulfilOrder(array $attributes = []): Order
{
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 10_000_000]);

    return Order::factory()->create($attributes + [
        'company_id' => $company->id,
        'status' => 'confirmed',
        'payment_method' => 'on_account',
        'payment_status' => 'on_account',
    ]);
}

/**
 * One order line for `$packQty` packs of `$pack`, with money that agrees
 * with itself (£1.00 a unit, 20% VAT), stock on hand, and allocated through
 * AllocationService — so movements and counters are as checkout leaves them.
 */
function fulfilLine(Order $order, Pack $pack, int $packQty, int $lineNo = 1, ?Batch $batch = null, int $onHand = 100, ?Bin $bin = null): OrderLine
{
    $baseQty = $packQty * $pack->base_units;
    $net = $baseQty * 100;
    $line = OrderLine::factory()->for($order)->forPack($pack, $packQty)->create([
        'line_no' => $lineNo,
        'unit_price_net_e4' => 10000,
        'line_net_minor' => $net,
        'line_tax_minor' => intdiv($net * 2000 + 5000, 10000),
        'line_gross_minor' => $net + intdiv($net * 2000 + 5000, 10000),
        'allocated_base_qty' => 0,
    ]);

    $level = StockLevel::identity($pack->sku_id, test()->location->id, $batch?->id)->first();
    if ($level === null) {
        $factory = StockLevel::factory()->for(Sku::query()->findOrFail($pack->sku_id))->for(test()->location);
        ($batch === null ? $factory : $factory->forBatch($batch))->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);
    }

    $allocation = (new AllocationService)->allocate(null, 0, [new AllocationLine($line->id, $pack->sku_id, test()->location->id, $batch?->id, $baseQty)])[0];
    if ($bin !== null) {
        $allocation->forceFill(['suggested_bin_id' => $bin->id])->save();
    }

    return $line;
}

function fulfilAllocation(OrderLine $line): StockAllocation
{
    return StockAllocation::query()->where('order_line_id', $line->id)->whereIn('status', ['allocated', 'picked'])->orderBy('id')->firstOrFail();
}

function fulfilRejection(Closure $call): FulfilmentRejectedException
{
    try {
        $call();
    } catch (FulfilmentRejectedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a FulfilmentRejectedException.');
}

function fulfilLevel(int $skuId, int $locationId, ?int $batchId = null): StockLevel
{
    return StockLevel::identity($skuId, $locationId, $batchId)->firstOrFail();
}

/** A serial-tracked line of `$qty` eaches with its serials reserved to it (04 §6.1). */
function fulfilSerialLine(Order $order, int $qty = 2, int $lineNo = 1): OrderLine
{
    $sku = Sku::factory()->create(['tracking_mode' => 'serial', 'sku_code' => 'SERIAL-'.$lineNo]);
    $pack = Pack::factory()->for($sku)->create();
    $line = fulfilLine($order, $pack, $qty, $lineNo, onHand: $qty);
    foreach (range(1, $qty) as $i) {
        StockSerial::factory()->allocated($line->id)->create(['sku_id' => $sku->id, 'serial_number' => "SN-{$lineNo}-{$i}", 'location_id' => test()->location->id]);
    }

    return $line;
}

// --- Shipments and pick lists (05.5 §5.1–5.2) ------------------------------

it('opens one shipment per order and location, and moves the order to picking', function () {
    $order = fulfilOrder();
    fulfilLine($order, Pack::factory()->outer(12)->create(), 3);

    $first = $this->generator->open($order->id, $this->location->id);
    $again = $this->generator->open($order->id, $this->location->id);

    expect($again->id)->toBe($first->id)
        ->and($first->status)->toBe('pending')
        ->and($first->fulfilment_type)->toBe('delivery')
        ->and(Shipment::query()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe('picking');
});

it('does not release an unpaid prepaid order to the warehouse', function () {
    $order = fulfilOrder(['payment_method' => 'card', 'payment_status' => 'unpaid']);
    fulfilLine($order, Pack::factory()->create(), 1);

    $e = fulfilRejection(fn () => $this->generator->open($order->id, $this->location->id));

    expect($e->errorCode)->toBe('awaiting_payment')->and(Shipment::query()->count())->toBe(0);
});

it('orders the pick list by bin walk sequence, then SKU code, in packs', function () {
    $order = fulfilOrder();
    $far = Bin::factory()->create(['location_id' => $this->location->id, 'code' => 'Z-99', 'walk_sequence' => 20]);
    $near = Bin::factory()->create(['location_id' => $this->location->id, 'code' => 'A-01', 'walk_sequence' => 10]);
    $packB = Pack::factory()->outer(12)->for(Sku::factory()->create(['sku_code' => 'BBB']))->create();
    $packA = Pack::factory()->outer(6)->for(Sku::factory()->create(['sku_code' => 'AAA']))->create();
    $packC = Pack::factory()->for(Sku::factory()->create(['sku_code' => 'CCC']))->create();
    fulfilLine($order, $packB, 3, 1, bin: $far);
    fulfilLine($order, $packA, 2, 2, bin: $near);
    fulfilLine($order, $packC, 5, 3, bin: $near);

    $list = $this->generator->generate($this->generator->open($order->id, $this->location->id));

    expect(array_map(fn ($l) => $l->skuCode, $list->lines))->toBe(['AAA', 'CCC', 'BBB'])
        ->and($list->lines[0]->binCode)->toBe('A-01')
        ->and($list->lines[2]->packs())->toBe(3)
        ->and($list->lines[2]->looseUnits())->toBe(0)
        ->and($list->lines[2]->baseQty)->toBe(36);
});

it('names the exact batch and the reserved serials on each line', function () {
    $order = fulfilOrder();
    $sku = Sku::factory()->batchTracked()->create();
    $batch = Batch::factory()->for($sku)->create(['batch_code' => 'LOT-7', 'expires_on' => now()->addYear()->toDateString()]);
    fulfilLine($order, Pack::factory()->for($sku)->create(), 4, 1, $batch);
    fulfilSerialLine($order, 2, 2);

    $list = $this->generator->generate($this->generator->open($order->id, $this->location->id));
    $byLine = collect($list->lines)->keyBy('lineNo');

    expect($byLine[1]->batchCode)->toBe('LOT-7')
        ->and($byLine[1]->expiresOn)->toBe(now()->addYear()->toDateString())
        ->and(array_column($byLine[2]->serials, 'serial_number'))->toBe(['SN-2-1', 'SN-2-2']);
});

// --- Serial scans: blocked, not warned (05.5 §5.2) -------------------------

it('blocks scanning a serial that is not allocated to this order, and changes nothing', function (string $case) {
    $order = fulfilOrder();
    $line = fulfilSerialLine($order);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $sku = Sku::query()->findOrFail($line->sku_id);

    if ($case === 'in stock') {
        StockSerial::factory()->create(['sku_id' => $sku->id, 'serial_number' => 'SN-OTHER', 'status' => 'in_stock', 'location_id' => $this->location->id]);
    } elseif ($case === 'another order') {
        $otherLine = OrderLine::factory()->for(fulfilOrder())->create(['sku_id' => $sku->id]);
        StockSerial::factory()->allocated($otherLine->id)->create(['sku_id' => $sku->id, 'serial_number' => 'SN-OTHER', 'location_id' => $this->location->id]);
    }

    $e = fulfilRejection(fn () => $this->picking->scanSerial($shipment, 'SN-OTHER'));

    expect($e->errorCode)->toBe('serial_not_allocated')
        ->and(StockSerial::query()->where('status', 'picked')->count())->toBe(0)
        ->and(fulfilAllocation($line)->status)->toBe('allocated');
})->with(['in stock', 'another order', 'unknown']);

it('picks a reserved serial, answers a repeat scan without repeating, and picks the line on the last one', function () {
    $order = fulfilOrder();
    $line = fulfilSerialLine($order);
    $shipment = $this->generator->open($order->id, $this->location->id);

    $first = $this->picking->scanSerial($shipment, 'SN-1-1');
    $repeat = $this->picking->scanSerial($shipment, ' SN-1-1 ');

    expect($first['replayed'])->toBeFalse()
        ->and($repeat['replayed'])->toBeTrue()
        ->and(fulfilAllocation($line)->status)->toBe('allocated')
        ->and($shipment->fresh()->status)->toBe('picking');

    $this->picking->scanSerial($shipment, 'SN-1-2');

    expect(fulfilAllocation($line)->status)->toBe('picked')
        ->and($shipment->fresh()->status)->toBe('picked')
        ->and(StockSerial::query()->where('status', 'picked')->count())->toBe(2);
});

it('will not confirm a serial-tracked line until every serial is scanned', function () {
    $order = fulfilOrder();
    $line = fulfilSerialLine($order);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->scanSerial($shipment, 'SN-1-1');

    $e = fulfilRejection(fn () => $this->picking->confirm($shipment, fulfilAllocation($line)->id));

    expect($e->errorCode)->toBe('serials_not_scanned')->and($e->meta)->toBe(['scanned' => 1, 'required' => 2]);
});

it('confirms an untracked line and marks the shipment picked when the list is done', function () {
    $order = fulfilOrder();
    $line = fulfilLine($order, Pack::factory()->outer(12)->create(), 3);
    $shipment = $this->generator->open($order->id, $this->location->id);

    $this->picking->confirm($shipment, fulfilAllocation($line)->id);

    expect(fulfilAllocation($line)->status)->toBe('picked')
        ->and($shipment->fresh()->status)->toBe('picked')
        ->and($shipment->fresh()->picked_at)->not->toBeNull()
        ->and($this->generator->generate($shipment)->isComplete())->toBeTrue();
});

// --- Short picks (05.5 §5.4) -----------------------------------------------

it('records a short pick: adjustment with the reason, picked quantity kept, remainder backordered', function () {
    Event::fake([ShortPickRecorded::class]);
    $order = fulfilOrder();
    $pack = Pack::factory()->outer(12)->create();
    $line = fulfilLine($order, $pack, 3); // 36 allocated of 100 on hand
    $shipment = $this->generator->open($order->id, $this->location->id);
    $picker = User::factory()->create();

    $outcome = $this->picking->shortPick($shipment, fulfilAllocation($line)->id, 24, ShortPickReason::NotFound, $picker->id);

    expect($outcome->shortfallBaseQty)->toBe(12)
        ->and($outcome->replannedBaseQty)->toBe(0)
        ->and($outcome->backorderedBaseQty())->toBe(12);

    $allocation = fulfilAllocation($line);
    expect($allocation->status)->toBe('picked')->and($allocation->base_qty)->toBe(24);

    $level = fulfilLevel($pack->sku_id, $this->location->id);
    expect($level->on_hand_base_qty)->toBe(88)->and($level->allocated_base_qty)->toBe(24);

    $adjustment = DB::table('stock_movements')->where('movement_type', 'adjustment')->sole();
    expect($adjustment->base_qty)->toBe(-12)
        ->and($adjustment->reason_code)->toBe('not_found')
        ->and($adjustment->actor_user_id)->toBe($picker->id)
        ->and($adjustment->reference_type)->toBe('allocation')
        ->and($adjustment->reference_id)->toBe($allocation->id);
    expect((int) DB::table('stock_movements')->where('movement_type', 'deallocation')->sum('base_qty'))->toBe(-12);

    expect($line->fresh()->allocated_base_qty)->toBe(24)
        ->and($shipment->fresh()->status)->toBe('picked');
    Event::assertDispatched(ShortPickRecorded::class, fn (ShortPickRecorded $e) => $e->shortfallBaseQty === 12 && $e->reason === 'not_found');
});

it('re-plans a short pick from another eligible batch (04 §5.1, strategy order)', function () {
    $order = fulfilOrder();
    $sku = Sku::factory()->batchTracked()->create();
    $pack = Pack::factory()->for($sku)->create();
    $a = Batch::factory()->for($sku)->create(['batch_code' => 'A', 'expires_on' => now()->addDays(200)->toDateString()]);
    $b = Batch::factory()->for($sku)->create(['batch_code' => 'B', 'expires_on' => now()->addDays(300)->toDateString()]);
    StockLevel::factory()->for($sku)->for($this->location)->forBatch($b)->create(['on_hand_base_qty' => 50, 'allocated_base_qty' => 0]);
    $line = fulfilLine($order, $pack, 10, 1, $a, onHand: 10);
    $shipment = $this->generator->open($order->id, $this->location->id);

    $outcome = $this->picking->shortPick($shipment, fulfilAllocation($line)->id, 6, ShortPickReason::Damaged, null);

    expect($outcome->replannedBaseQty)->toBe(4)
        ->and($line->fresh()->allocated_base_qty)->toBe(10)
        ->and(StockAllocation::query()->where('order_line_id', $line->id)->where('batch_id', $b->id)->sole()->base_qty)->toBe(4)
        ->and(fulfilLevel($sku->id, $this->location->id, $b->id)->allocated_base_qty)->toBe(4)
        ->and(fulfilLevel($sku->id, $this->location->id, $a->id)->on_hand_base_qty)->toBe(6);

    // The re-planned stock joins the open shipment's list, still to pick.
    expect(collect($this->generator->generate($shipment)->lines)->pluck('batchCode')->all())->toContain('B');
});

it('releases the allocation when nothing was picked', function () {
    $order = fulfilOrder();
    $line = fulfilLine($order, Pack::factory()->create(), 5);
    $shipment = $this->generator->open($order->id, $this->location->id);

    $this->picking->shortPick($shipment, fulfilAllocation($line)->id, 0, ShortPickReason::WrongLocation, null);

    expect(StockAllocation::query()->where('order_line_id', $line->id)->sole()->status)->toBe('released')
        ->and($line->fresh()->allocated_base_qty)->toBe(0);
});

it('quarantines the unscanned serials of a serial-tracked short pick', function () {
    $order = fulfilOrder();
    $line = fulfilSerialLine($order, 3);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->scanSerial($shipment, 'SN-1-1');

    $e = fulfilRejection(fn () => $this->picking->shortPick($shipment, fulfilAllocation($line)->id, 2, ShortPickReason::NotFound, null));
    expect($e->errorCode)->toBe('picked_qty_must_match_scanned_serials');

    $outcome = $this->picking->shortPick($shipment, fulfilAllocation($line)->id, 1, ShortPickReason::NotFound, null);

    expect($outcome->replannedBaseQty)->toBe(0)
        ->and(StockSerial::query()->where('status', 'quarantined')->whereNull('order_line_id')->count())->toBe(2)
        ->and(StockSerial::query()->where('status', 'picked')->count())->toBe(1);

    // 04 §6.3: on_hand = serials in_stock/allocated/picked.
    expect(fulfilLevel($line->sku_id, $this->location->id)->on_hand_base_qty)->toBe(1);
});

// --- Batch substitution (05.5 §5.3) ----------------------------------------

it('substitutes a batch explicitly: deallocation, allocation, and a record of who and why', function () {
    Event::fake([BatchSubstituted::class]);
    $order = fulfilOrder();
    $sku = Sku::factory()->batchTracked()->create();
    $a = Batch::factory()->for($sku)->create(['batch_code' => 'A-LOT', 'expires_on' => now()->addDays(200)->toDateString()]);
    $b = Batch::factory()->for($sku)->create(['batch_code' => 'B-LOT', 'expires_on' => now()->addDays(300)->toDateString()]);
    StockLevel::factory()->for($sku)->for($this->location)->forBatch($b)->create(['on_hand_base_qty' => 20, 'allocated_base_qty' => 0]);
    $line = fulfilLine($order, Pack::factory()->for($sku)->create(), 8, 1, $a, onHand: 20);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $picker = User::factory()->create();
    $original = fulfilAllocation($line);

    $new = (new BatchSubstitutionService)->substitute($shipment, $original->id, $b->id, 'behind a full pallet', $picker->id);

    expect($original->fresh()->status)->toBe('released')
        ->and($new->batch_id)->toBe($b->id)
        ->and($new->base_qty)->toBe(8)
        ->and(fulfilLevel($sku->id, $this->location->id, $a->id)->allocated_base_qty)->toBe(0)
        ->and(fulfilLevel($sku->id, $this->location->id, $b->id)->allocated_base_qty)->toBe(8)
        ->and($line->fresh()->allocated_base_qty)->toBe(8);

    $movements = DB::table('stock_movements')->where('reason_code', 'batch_substitution')->orderBy('id')->get();
    expect($movements->pluck('movement_type')->all())->toBe(['deallocation', 'allocation'])
        ->and($movements->pluck('actor_user_id')->unique()->all())->toBe([$picker->id])
        ->and($movements[0]->note)->toContain('A-LOT')->toContain('B-LOT')->toContain('behind a full pallet');
    Event::assertDispatched(BatchSubstituted::class, fn (BatchSubstituted $e) => $e->fromBatchId === $a->id && $e->toBatchId === $b->id);
});

it('refuses a substitution that is not allowed', function (string $case, string $code) {
    $order = fulfilOrder();
    $sku = Sku::factory()->batchTracked()->create();
    $a = Batch::factory()->for($sku)->create(['expires_on' => now()->addDays(200)->toDateString()]);
    $b = Batch::factory()->for($sku)->create(['expires_on' => now()->addDays(300)->toDateString(), 'status' => $case === 'inactive batch' ? 'quarantined' : 'active']);
    StockLevel::factory()->for($sku)->for($this->location)->forBatch($b)->create(['on_hand_base_qty' => 20, 'allocated_base_qty' => 0]);
    $line = fulfilLine($order, Pack::factory()->for($sku)->create(), 8, 1, $a, onHand: 20);
    $shipment = $this->generator->open($order->id, $this->location->id);
    if ($case === 'already picked') {
        $this->picking->confirm($shipment, fulfilAllocation($line)->id);
    }

    $e = fulfilRejection(fn () => (new BatchSubstitutionService)->substitute($shipment, fulfilAllocation($line)->id, $b->id, $case === 'no reason' ? ' ' : 'unreachable', null));

    expect($e->errorCode)->toBe($code)
        ->and(DB::table('stock_movements')->where('reason_code', 'batch_substitution')->count())->toBe(0);
})->with([
    ['already picked', 'line_already_picked'],
    ['inactive batch', 'batch_not_eligible'],
    ['no reason', 'substitution_reason_required'],
]);

// --- Dispatch (04 §7.2, 05.5 §7) -------------------------------------------

it('refuses to dispatch while a line is unpicked, writing nothing', function () {
    $order = fulfilOrder();
    fulfilLine($order, Pack::factory()->create(), 5);
    $shipment = $this->generator->open($order->id, $this->location->id);

    $e = fulfilRejection(fn () => $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD')));

    expect($e->errorCode)->toBe('unpicked_lines')
        ->and($e->meta['line_nos'])->toBe([1])
        ->and(DB::table('stock_movements')->where('movement_type', 'dispatch')->count())->toBe(0)
        ->and(ShipmentLine::query()->count())->toBe(0);
});

it('dispatches atomically: on_hand and allocated down together, ledger, allocation, lines and order status', function () {
    Event::fake([ShipmentDispatched::class]);
    $order = fulfilOrder();
    $pack = Pack::factory()->outer(12)->create();
    $line = fulfilLine($order, $pack, 3);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->confirm($shipment, fulfilAllocation($line)->id);
    $dispatcher = User::factory()->create();

    $outcome = $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD', '1Z999', 2, 12500, null, $dispatcher->id));

    expect($outcome->replayed)->toBeFalse()->and($outcome->orderFullyDispatched)->toBeTrue();

    $level = fulfilLevel($pack->sku_id, $this->location->id);
    expect($level->on_hand_base_qty)->toBe(64)->and($level->allocated_base_qty)->toBe(0);

    $allocation = StockAllocation::query()->where('order_line_id', $line->id)->sole();
    $movement = DB::table('stock_movements')->where('movement_type', 'dispatch')->sole();
    expect($movement->base_qty)->toBe(-36)
        ->and($movement->reference_type)->toBe('allocation')
        ->and($movement->reference_id)->toBe($allocation->id)
        ->and($movement->actor_user_id)->toBe($dispatcher->id)
        ->and($allocation->status)->toBe('dispatched');

    expect(ShipmentLine::query()->sole()->dispatched_base_qty)->toBe(36)
        ->and($line->fresh()->dispatched_base_qty)->toBe(36)
        ->and($line->fresh()->allocated_base_qty)->toBe(0);

    $order->refresh();
    $shipment->refresh();
    expect($order->status)->toBe('dispatched')
        ->and($order->dispatched_at)->not->toBeNull()
        ->and($shipment->status)->toBe('dispatched')
        ->and($shipment->carrier)->toBe('DPD')
        ->and($shipment->tracking_number)->toBe('1Z999')
        ->and($shipment->total_weight_g)->toBe(12500);
    Event::assertDispatched(ShipmentDispatched::class, fn (ShipmentDispatched $e) => $e->shipmentId === $shipment->id && $e->orderFullyDispatched);
});

it('writes shipment_line_batches and shipment_line_serials, and dispatches the serials', function () {
    $order = fulfilOrder();
    $sku = Sku::factory()->batchTracked()->create();
    $batch = Batch::factory()->for($sku)->create(['expires_on' => now()->addYear()->toDateString()]);
    $batchLine = fulfilLine($order, Pack::factory()->for($sku)->create(), 4, 1, $batch);
    $serialLine = fulfilSerialLine($order, 2, 2);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->confirm($shipment, fulfilAllocation($batchLine)->id);
    $this->picking->scanSerial($shipment, 'SN-2-1');
    $this->picking->scanSerial($shipment, 'SN-2-2');

    $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD'));

    $batchShipmentLine = ShipmentLine::query()->where('order_line_id', $batchLine->id)->sole();
    expect(ShipmentLineBatch::query()->where('shipment_line_id', $batchShipmentLine->id)->sole()->only(['batch_id', 'base_qty']))->toBe(['batch_id' => $batch->id, 'base_qty' => 4]);

    $serialShipmentLine = ShipmentLine::query()->where('order_line_id', $serialLine->id)->sole();
    expect(ShipmentLineSerial::query()->where('shipment_line_id', $serialShipmentLine->id)->count())->toBe(2);

    $movementId = DB::table('stock_movements')->where('movement_type', 'dispatch')->where('sku_id', $serialLine->sku_id)->value('id');
    expect(StockSerial::query()->where('status', 'dispatched')->where('dispatched_movement_id', $movementId)->count())->toBe(2);
});

it('part-dispatches after a short pick, leaving the order part_dispatched and the customer told what remains', function () {
    $order = fulfilOrder();
    $pack = Pack::factory()->outer(12)->create();
    $line = fulfilLine($order, $pack, 3);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->shortPick($shipment, fulfilAllocation($line)->id, 24, ShortPickReason::QuantityShort, null);

    $outcome = $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD'));

    expect($outcome->orderFullyDispatched)->toBeFalse()
        ->and($order->fresh()->status)->toBe('part_dispatched')
        ->and($line->fresh()->dispatched_base_qty)->toBe(24);

    $content = (new ShipmentDispatchedNotice($shipment->id))->content(new Recipient('buyer@example.test'));
    $text = implode("\n", $content->paragraphs);
    expect($content->subject)->toContain('part dispatched')
        ->and($text)->toContain('2 × Outer of 12 (24 units)')
        ->and($text)->toContain('Still to come')
        ->and($text)->toContain('1 × Outer of 12 (12 units)');
});

it('is idempotent on the shipment: a retried dispatch writes one movement set and one notice', function () {
    $order = fulfilOrder();
    $line = fulfilLine($order, Pack::factory()->create(), 5);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->confirm($shipment, fulfilAllocation($line)->id);

    $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD'));
    $retry = $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD'));

    expect($retry->replayed)->toBeTrue()
        ->and(DB::table('stock_movements')->where('movement_type', 'dispatch')->count())->toBe(1)
        ->and(ShipmentLine::query()->count())->toBe(1)
        ->and(fulfilLevel($line->sku_id, $this->location->id)->on_hand_base_qty)->toBe(95)
        ->and(DB::table('notification_log')->where('notification_key', NotificationKey::ShipmentDispatched->value)->count())->toBe(1);
});

it('loses to a cancellation of the order: nothing leaves (05.5 §13 W2, sequential)', function () {
    $order = fulfilOrder();
    $line = fulfilLine($order, Pack::factory()->create(), 5);
    $shipment = $this->generator->open($order->id, $this->location->id);
    $this->picking->confirm($shipment, fulfilAllocation($line)->id);
    $order->forceFill(['status' => 'cancelled'])->save();

    $e = fulfilRejection(fn () => $this->dispatcher->dispatch($shipment, new DispatchDetails('DPD')));

    expect($e->errorCode)->toBe('order_not_workable')
        ->and(fulfilLevel($line->sku_id, $this->location->id)->allocated_base_qty)->toBe(5)
        ->and(DB::table('stock_movements')->where('movement_type', 'dispatch')->count())->toBe(0);
});
