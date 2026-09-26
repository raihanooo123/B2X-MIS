<?php

use App\Domain\Warehouse\Events\StocktakePosted;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Domain\Warehouse\StocktakeReason;
use App\Domain\Warehouse\StocktakeService;
use App\Models\Batch;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockSerial;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\StocktakeLineSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * StocktakeService — 05.5 §8, 04 §7.4, 02 §24. The headline case: count
 * 10, sell 2, post → variance 0, not +2 (§24.1). Every refusal is
 * asserted to write nothing to the ledger.
 */
beforeEach(function () {
    $this->location = Location::factory()->default()->create();
    $this->service = new StocktakeService;
    Carbon::setTestNow('2026-09-26 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function stocktakeSku(int $onHand, array $state = [], ?Batch $batch = null): Sku
{
    $sku = $batch?->sku ?? Sku::factory()->create($state);
    $factory = StockLevel::factory()->for($sku)->for(test()->location);
    ($batch === null ? $factory : $factory->forBatch($batch))->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);

    return $sku;
}

/** An on-hand movement after the count — trading carries on (05.5 §8). */
function stocktakeTrade(Sku $sku, int $baseQty, string $type = 'dispatch', ?int $batchId = null): void
{
    $movement = StockMovement::create([
        'occurred_at' => now(),
        'sku_id' => $sku->id,
        'location_id' => test()->location->id,
        'batch_id' => $batchId,
        'movement_type' => $type,
        'base_qty' => $baseQty,
    ]);
    StockLevel::identity($sku->id, test()->location->id, $batchId)->update([
        'on_hand_base_qty' => DB::raw('on_hand_base_qty + '.$baseQty),
        'last_movement_id' => $movement->id,
    ]);
}

function stocktakeRejection(Closure $call): FulfilmentRejectedException
{
    try {
        $call();
    } catch (FulfilmentRejectedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a FulfilmentRejectedException.');
}

function stocktakeOnHand(Sku $sku, ?int $batchId = null): int
{
    return StockLevel::identity($sku->id, test()->location->id, $batchId)->firstOrFail()->on_hand_base_qty;
}

function stocktakeLater(int $minutes): void
{
    Carbon::setTestNow(now()->addMinutes($minutes));
}

// --- Sessions and counting --------------------------------------------------

it('keeps one session per location: starting again resumes it', function () {
    $first = $this->service->start($this->location->id, true, null);
    $again = $this->service->start($this->location->id, false, null);

    expect($again->id)->toBe($first->id)
        ->and($first->is_blind)->toBeTrue()
        ->and($first->status)->toBe('open')
        ->and(Stocktake::query()->count())->toBe(1);
});

it('writes nothing to the ledger while counting', function () {
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);

    $this->service->count($stocktake, $sku, null, 7, null);

    expect(StockMovement::query()->count())->toBe(0)
        ->and(stocktakeOnHand($sku))->toBe(10)
        ->and(StocktakeLine::query()->sole()->expected_base_qty)->toBeNull()
        ->and(StocktakeLine::query()->sole()->variance_base_qty)->toBeNull();
});

it('replaces a line on recount, and measures it from the recount', function () {
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 7, null);
    stocktakeLater(5);

    $line = $this->service->count($stocktake, $sku, null, 9, null);

    expect(StocktakeLine::query()->count())->toBe(1)
        ->and($line->counted_base_qty)->toBe(9)
        ->and($line->counted_at->toDateTimeString())->toBe('2026-09-26 10:05:00');
});

it('requires a batch for a batch-tracked SKU and refuses one for an untracked SKU', function () {
    $stocktake = $this->service->start($this->location->id, false, null);
    $tracked = stocktakeSku(5, ['tracking_mode' => 'batch']);
    $untracked = stocktakeSku(5);
    $batch = Batch::factory()->for($untracked)->create();

    expect(stocktakeRejection(fn () => $this->service->count($stocktake, $tracked, null, 5, null))->errorCode)->toBe('batch_code_required')
        ->and(stocktakeRejection(fn () => $this->service->count($stocktake, $untracked, $batch, 5, null))->errorCode)->toBe('batch_not_tracked');
});

it('stops counting in review, and counts again once reopened', function () {
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 10, null);
    $this->service->submitForReview($stocktake);

    expect(stocktakeRejection(fn () => $this->service->count($stocktake, $sku, null, 8, null))->errorCode)->toBe('stocktake_not_counting');

    $this->service->reopen($stocktake);
    expect($this->service->count($stocktake, $sku, null, 8, null)->counted_base_qty)->toBe(8);
});

// --- Variance at count time (02 §24.1) --------------------------------------

it('gives variance 0 for count 10, sell 2, post — not +2', function () {
    Event::fake([StocktakePosted::class]);
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 10, null);
    stocktakeLater(1);
    stocktakeTrade($sku, -2);
    stocktakeLater(1);

    $posted = $this->service->post($stocktake, [], null);

    $line = StocktakeLine::query()->sole();
    expect($posted->status)->toBe('posted')
        ->and($line->expected_base_qty)->toBe(10)
        ->and($line->variance_base_qty)->toBe(0)
        ->and($line->posted_movement_id)->toBeNull()
        ->and(StockMovement::query()->where('movement_type', 'stocktake')->count())->toBe(0)
        ->and(stocktakeOnHand($sku))->toBe(8);
    Event::assertDispatched(StocktakePosted::class, fn (StocktakePosted $e) => $e->movementCount === 0);
});

it('posts a real variance as one stocktake movement with its reason, applied to the current level', function () {
    $sku = stocktakeSku(10);
    $counter = User::factory()->create();
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 7, $counter->id);
    stocktakeLater(1);
    stocktakeTrade($sku, -2);                      // sold after the count
    stocktakeTrade($sku, 5, 'goods_in');           // received after the count
    stocktakeLater(1);
    $line = StocktakeLine::query()->sole();

    $this->service->post($stocktake, [$line->id => StocktakeReason::LossOrTheft], $counter->id);

    $line->refresh();
    $movement = StockMovement::query()->where('movement_type', 'stocktake')->sole();
    expect($line->expected_base_qty)->toBe(10)
        ->and($line->variance_base_qty)->toBe(-3)
        ->and($line->reason_code)->toBe('loss_or_theft')
        ->and($line->posted_movement_id)->toBe($movement->id)
        ->and($movement->base_qty)->toBe(-3)
        ->and($movement->reason_code)->toBe('loss_or_theft')
        ->and($movement->reference_type)->toBe('stocktake_line')
        ->and($movement->reference_id)->toBe($line->id)
        ->and($movement->actor_user_id)->toBe($counter->id)
        ->and(stocktakeOnHand($sku))->toBe(10 - 2 + 5 - 3);
});

it('ignores allocation movements when reconstructing — they never touched on_hand', function () {
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 10, null);
    stocktakeLater(1);
    StockMovement::create(['occurred_at' => now(), 'sku_id' => $sku->id, 'location_id' => $this->location->id, 'movement_type' => 'allocation', 'base_qty' => 4]);

    $review = $this->service->review($stocktake)[0];

    expect($review->expectedAtCount)->toBe(10)->and($review->variance())->toBe(0);
});

it('refuses to post a variance without a reason, naming the line, and writes nothing', function () {
    $sku = stocktakeSku(10, ['sku_code' => 'WIDGET']);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 12, null);

    $e = stocktakeRejection(fn () => $this->service->post($stocktake, [], null));

    expect($e->errorCode)->toBe('stocktake_not_postable')
        ->and($e->details[0]['code'])->toBe('variance_reason_required')
        ->and($e->details[0]['message'])->toContain('WIDGET')->toContain('+2')
        ->and($stocktake->fresh()->status)->toBe('open')
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(stocktakeOnHand($sku))->toBe(10);
});

it('posts counted stock of a batch with no level row yet', function () {
    $sku = Sku::factory()->create(['tracking_mode' => 'batch']);
    $batch = Batch::factory()->for($sku)->create();
    $stocktake = $this->service->start($this->location->id, false, null);
    $line = $this->service->count($stocktake, $sku, $batch, 6, null);

    $this->service->post($stocktake, [$line->id => StocktakeReason::FoundStock], null);

    expect(stocktakeOnHand($sku, $batch->id))->toBe(6)
        ->and($line->fresh()->expected_base_qty)->toBe(0);
});

it('is idempotent on the stocktake: posting twice writes once', function () {
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);
    $line = $this->service->count($stocktake, $sku, null, 8, null);

    $this->service->post($stocktake, [$line->id => StocktakeReason::Damaged], null);
    $this->service->post($stocktake, [$line->id => StocktakeReason::Damaged], null);

    expect(StockMovement::query()->where('movement_type', 'stocktake')->count())->toBe(1)
        ->and(stocktakeOnHand($sku))->toBe(8);
});

it('cancels without touching stock', function () {
    $sku = stocktakeSku(10);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->count($stocktake, $sku, null, 3, null);

    expect($this->service->cancel($stocktake)->status)->toBe('cancelled')
        ->and(stocktakeOnHand($sku))->toBe(10)
        ->and(stocktakeRejection(fn () => $this->service->post($stocktake, [], null))->errorCode)->toBe('stocktake_not_open');
});

// --- Serials, by number (02 §24.2) ------------------------------------------

/** A serial-tracked SKU with S1–S3 in stock here. */
function stocktakeSerialSku(): Sku
{
    $sku = stocktakeSku(3, ['tracking_mode' => 'serial', 'sku_code' => 'PHONE']);
    foreach (['S1', 'S2', 'S3'] as $number) {
        StockSerial::factory()->create(['sku_id' => $sku->id, 'serial_number' => $number, 'status' => 'in_stock', 'location_id' => test()->location->id]);
    }

    return $sku;
}

it('counts a serial-tracked SKU by scanning, once per serial', function () {
    $sku = stocktakeSerialSku();
    $stocktake = $this->service->start($this->location->id, false, null);

    $this->service->scanSerial($stocktake, $sku, null, 'S1', null);
    $repeat = $this->service->scanSerial($stocktake, $sku, null, 'S1', null);
    $this->service->scanSerial($stocktake, $sku, null, 'S2', null);

    expect($repeat['replayed'])->toBeTrue()
        ->and(StocktakeLineSerial::query()->count())->toBe(2)
        ->and(StocktakeLine::query()->sole()->counted_base_qty)->toBe(2)
        ->and(stocktakeRejection(fn () => $this->service->count($stocktake, $sku, null, 3, null))->errorCode)->toBe('count_by_scanning');

    $this->service->removeSerial($stocktake, $sku, null, 'S2', null);
    expect(StocktakeLine::query()->sole()->counted_base_qty)->toBe(1);
});

it('lists missing and found serials by number, and reconciles them at posting', function () {
    $sku = stocktakeSerialSku();
    $stocktake = $this->service->start($this->location->id, false, null);
    foreach (['S1', 'S2', 'NEW-9'] as $number) {
        $this->service->scanSerial($stocktake, $sku, null, $number, null);
    }

    $review = $this->service->review($stocktake)[0];
    expect($review->missingSerials)->toBe(['S3'])
        ->and($review->foundSerials)->toBe(['NEW-9'])
        ->and($review->variance())->toBe(0)
        ->and($review->blockers)->toBe([]);

    $this->service->post($stocktake, [], null);

    expect(StockSerial::query()->where('serial_number', 'S3')->value('status'))->toBe('quarantined')
        ->and(StockSerial::query()->where('serial_number', 'NEW-9')->sole()->status)->toBe('in_stock')
        ->and(StocktakeLineSerial::query()->where('serial_number', 'NEW-9')->value('serial_id'))->not->toBeNull();
});

it('blocks posting while a missing serial is reserved to an order', function () {
    $sku = stocktakeSerialSku();
    $orderLine = OrderLine::factory()->create(['sku_id' => $sku->id]);
    StockSerial::query()->where('serial_number', 'S3')->update(['status' => 'allocated', 'order_line_id' => $orderLine->id]);
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->scanSerial($stocktake, $sku, null, 'S1', null);
    $this->service->scanSerial($stocktake, $sku, null, 'S2', null);
    $line = StocktakeLine::query()->sole();

    $e = stocktakeRejection(fn () => $this->service->post($stocktake, [$line->id => StocktakeReason::LossOrTheft], null));

    expect($e->details[0]['code'])->toBe('line_blocked')
        ->and($e->details[0]['message'])->toContain('S3')
        ->and(StockSerial::query()->where('serial_number', 'S3')->value('status'))->toBe('allocated')
        ->and(StockMovement::query()->count())->toBe(0);
});

it('refuses to absorb drift between serial records and the level (04 §6.3)', function () {
    $sku = stocktakeSerialSku();
    StockSerial::query()->where('serial_number', 'S3')->delete(); // level says 3, records say 2
    $stocktake = $this->service->start($this->location->id, false, null);
    $this->service->scanSerial($stocktake, $sku, null, 'S1', null);
    $this->service->scanSerial($stocktake, $sku, null, 'S2', null);

    $review = $this->service->review($stocktake)[0];

    expect($review->variance())->toBe(-1)
        ->and($review->missingSerials)->toBe([])
        ->and($review->blockers)->not->toBe([]);
});
