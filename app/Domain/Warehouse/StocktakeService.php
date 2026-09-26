<?php

namespace App\Domain\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Warehouse\Events\StocktakePosted;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockSerial;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\StocktakeLineSerial;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Stocktake — 05.5 §8, 04 §7.4, 02 §14.7 and §24.
 *
 * **A session per location**, at most one open at a time; start() resumes
 * it. Trading continues throughout.
 *
 * **Nothing reaches the ledger until posting.** Counting writes only
 * `stocktake_lines` (and `stocktake_line_serials`): a half-finished count
 * cannot move stock.
 *
 * **Counting.** count() sets a line's quantity for `(sku, batch)`. A recount
 * replaces it, and resets `counted_at`: the line is a statement about
 * that moment. Serial-tracked SKUs are counted by scanning: each scan is a
 * `stocktake_line_serials` row, the line's quantity is the number of
 * rows, and `counted_at` is the last scan.
 *
 * **Variance is against stock as it stood when the line was counted**
 * (02 §24.1, correcting §14.7): the locked level now, minus every on-hand
 * movement of the identity since `counted_at`. Count 10, sell 2, post:
 * variance 0. That figure is stored as `expected_base_qty`, so the
 * generated `variance_base_qty` is right, and the variance is applied to
 * the current level as one `stocktake` movement with its reason.
 *
 * **Serial reconciliation by number** (§24.2). Missing serials go to
 * `quarantined`; found ones come back `in_stock` here, or are created.
 * Posting is blocked if a missing serial is reserved to an order, or if
 * the serial differences disagree with the quantity variance — that
 * would be 04 §6.3 drift, a P1, never absorbed by a count.
 *
 * **Posting** is one transaction, retried whole on deadlock (04 §4.5):
 * `stocktakes` FOR UPDATE → `stock_levels` in 02 §11.1's order →
 * `stock_serials` (§24.4). Every line with a nonzero variance must carry
 * a reason (§24.3); all missing reasons are reported at once. Posting a
 * posted stocktake is answered with it, and writes nothing.
 *
 * **Blind counting** (05.5 §8) is a flag on the session: the API does not
 * show system figures while counting, so the operative counts rather than
 * confirms. Review shows them.
 */
final class StocktakeService
{
    /** 04 §2.2: the movement types that change on_hand. */
    public const ON_HAND_TYPES = ['goods_in', 'dispatch', 'return_in', 'adjustment', 'stocktake', 'transfer_in', 'transfer_out', 'write_off'];

    public const COUNTING_STATUSES = ['open', 'review'];

    /**
     * @throws FulfilmentRejectedException
     */
    public function start(int $locationId, bool $blind, ?int $actorUserId): Stocktake
    {
        return DB::transaction(function () use ($locationId, $blind, $actorUserId) {
            // Serialises two starts at one location, so only one session is open.
            Location::query()->lockForUpdate()->findOrFail($locationId);

            $open = Stocktake::query()->where('location_id', $locationId)->whereIn('status', self::COUNTING_STATUSES)->first();
            if ($open !== null) {
                return $open;
            }

            return Stocktake::create([
                'location_id' => $locationId,
                'status' => 'open',
                'is_blind' => $blind,
                'started_by_user_id' => $actorUserId,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Set the counted quantity for one `(sku, batch)` — a recount replaces
     * it. Serial-tracked SKUs are counted by scanning, except for an
     * explicit zero ("none on the shelf").
     *
     * @throws FulfilmentRejectedException
     */
    public function count(Stocktake $stocktake, Sku $sku, ?Batch $batch, int $countedBaseQty, ?int $actorUserId): StocktakeLine
    {
        if ($countedBaseQty < 0) {
            throw new FulfilmentRejectedException('invalid_count', 'A count cannot be negative.', 'counted_qty');
        }

        return DB::transaction(function () use ($stocktake, $sku, $batch, $countedBaseQty, $actorUserId) {
            $this->lockCounting($stocktake);
            $this->assertIdentity($sku, $batch);

            $line = $this->line($stocktake, $sku, $batch);
            if (TrackingMode::from($sku->tracking_mode)->tracksSerial()) {
                $scanned = $line === null ? 0 : $line->serials()->count();
                if ($countedBaseQty !== 0 || $scanned > 0) {
                    throw new FulfilmentRejectedException('count_by_scanning', "{$sku->sku_code} is serial-tracked: scan each unit. Enter zero only when there are none.", 'counted_qty');
                }
            }

            return $this->saveLine($stocktake, $sku, $batch, $line, $countedBaseQty, $actorUserId);
        });
    }

    /**
     * One scanned serial into its line. Idempotent: scanning the same
     * serial again changes nothing (`stocktake_line_serials_line_number_uq`).
     *
     * @return array{line: StocktakeLine, replayed: bool}
     *
     * @throws FulfilmentRejectedException
     */
    public function scanSerial(Stocktake $stocktake, Sku $sku, ?Batch $batch, string $serialNumber, ?int $actorUserId): array
    {
        $serialNumber = trim($serialNumber);
        if ($serialNumber === '') {
            throw new FulfilmentRejectedException('serial_required', 'Scan a serial.', 'serial_number');
        }
        if (! TrackingMode::from($sku->tracking_mode)->tracksSerial()) {
            throw new FulfilmentRejectedException('serial_not_tracked', "{$sku->sku_code} is not serial-tracked; count it by quantity.", 'serial_number');
        }

        return DB::transaction(function () use ($stocktake, $sku, $batch, $serialNumber, $actorUserId) {
            $this->lockCounting($stocktake);
            $this->assertIdentity($sku, $batch);

            $line = $this->line($stocktake, $sku, $batch) ?? $this->saveLine($stocktake, $sku, $batch, null, 0, $actorUserId);

            $inserted = DB::selectOne(<<<'SQL'
                INSERT INTO stocktake_line_serials (stocktake_line_id, serial_number, serial_id, scanned_by_user_id, scanned_at)
                VALUES (?, ?, ?, ?, now())
                ON CONFLICT ON CONSTRAINT stocktake_line_serials_line_number_uq DO NOTHING
                RETURNING id
            SQL, [$line->id, $serialNumber, StockSerial::query()->where('sku_id', $sku->id)->where('serial_number', $serialNumber)->value('id'), $actorUserId]);

            if (! is_object($inserted)) {
                return ['line' => $line, 'replayed' => true];
            }

            return ['line' => $this->saveLine($stocktake, $sku, $batch, $line, $line->serials()->count(), $actorUserId), 'replayed' => false];
        });
    }

    /**
     * Undo a mis-scan while counting.
     *
     * @throws FulfilmentRejectedException
     */
    public function removeSerial(Stocktake $stocktake, Sku $sku, ?Batch $batch, string $serialNumber, ?int $actorUserId): StocktakeLine
    {
        return DB::transaction(function () use ($stocktake, $sku, $batch, $serialNumber, $actorUserId) {
            $this->lockCounting($stocktake);
            $line = $this->line($stocktake, $sku, $batch)
                ?? throw new FulfilmentRejectedException('line_not_counted', 'Nothing has been counted for that SKU and batch.', 'sku_id');

            $line->serials()->where('serial_number', trim($serialNumber))->delete();

            return $this->saveLine($stocktake, $sku, $batch, $line, $line->serials()->count(), $actorUserId);
        });
    }

    /** Finish counting: the session goes to review. Counting can be reopened. */
    public function submitForReview(Stocktake $stocktake): Stocktake
    {
        return $this->transition($stocktake, 'open', 'review');
    }

    public function reopen(Stocktake $stocktake): Stocktake
    {
        return $this->transition($stocktake, 'review', 'open');
    }

    /**
     * @throws FulfilmentRejectedException
     */
    public function cancel(Stocktake $stocktake): Stocktake
    {
        return DB::transaction(function () use ($stocktake) {
            $locked = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            if (! in_array($locked->status, self::COUNTING_STATUSES, true)) {
                throw new FulfilmentRejectedException('stocktake_not_open', "This stocktake is {$locked->status}.", null, ['status' => $locked->status], 409);
            }
            $locked->forceFill(['status' => 'cancelled'])->save();

            return $locked;
        });
    }

    /**
     * What each line would post, without locking or writing — the review
     * screen (05.5 §8: "the delta shown for review").
     *
     * @return list<StocktakeLineReview>
     */
    public function review(Stocktake $stocktake): array
    {
        return array_map(fn (StocktakeLine $line) => $this->reviewLine($stocktake, $line), $this->linesInLockOrder($stocktake));
    }

    /**
     * @param  array<int, StocktakeReason>  $reasonsByLineId
     *
     * @throws FulfilmentRejectedException
     */
    public function post(Stocktake $stocktake, array $reasonsByLineId, ?int $actorUserId): Stocktake
    {
        $posted = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->postWithinTransaction($stocktake->id, $reasonsByLineId, $actorUserId)),
            self::class,
        );

        if ($posted['written']) {
            $event = new StocktakePosted($posted['stocktake']->id, $posted['movements']);
            DB::afterCommit(fn () => event($event));
        }

        return $posted['stocktake'];
    }

    /**
     * @param  array<int, StocktakeReason>  $reasonsByLineId
     * @return array{stocktake: Stocktake, written: bool, movements: int}
     */
    private function postWithinTransaction(int $stocktakeId, array $reasonsByLineId, ?int $actorUserId): array
    {
        $now = CarbonImmutable::now();
        $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktakeId);

        if ($stocktake->status === 'posted') {
            return ['stocktake' => $stocktake, 'written' => false, 'movements' => 0];
        }
        if (! in_array($stocktake->status, self::COUNTING_STATUSES, true)) {
            throw new FulfilmentRejectedException('stocktake_not_open', "This stocktake is {$stocktake->status}.", null, ['status' => $stocktake->status], 409);
        }

        $lines = $this->linesInLockOrder($stocktake);
        if ($lines === []) {
            throw new FulfilmentRejectedException('nothing_counted', 'Count something before posting.', null);
        }

        // §24.4: every level row, in the global order, before anything is read for the figures.
        foreach ($lines as $line) {
            StockLevel::identity($line->sku_id, $stocktake->location_id, $line->batch_id)->lockForUpdate()->first();
        }

        $reviews = array_map(fn (StocktakeLine $line) => $this->reviewLine($stocktake, $line), $lines);

        $problems = [];
        foreach ($reviews as $review) {
            $field = 'lines.'.$review->line->id;
            foreach ($review->blockers as $blocker) {
                $problems[] = ['field' => $field, 'code' => 'line_blocked', 'message' => $blocker];
            }
            if ($review->variance() !== 0 && ! isset($reasonsByLineId[$review->line->id])) {
                $problems[] = ['field' => $field, 'code' => 'variance_reason_required', 'message' => $this->describe($review).': variance '.sprintf('%+d', $review->variance()).'. Give a reason.', 'meta' => ['variance_base_qty' => $review->variance()]];
            }
        }
        if ($problems !== []) {
            throw new FulfilmentRejectedException('stocktake_not_postable', 'Some lines cannot post yet. Each needs attention first.', 'lines', [], 422, $problems);
        }

        $movements = 0;
        foreach ($reviews as $review) {
            $line = $review->line;
            $variance = $review->variance();
            $reason = $reasonsByLineId[$line->id] ?? null;
            $movementId = null;

            if ($variance !== 0 && $reason !== null) {
                $movement = StockMovement::create([
                    'occurred_at' => $now,
                    'sku_id' => $line->sku_id,
                    'location_id' => $stocktake->location_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => 'stocktake',
                    'base_qty' => $variance,
                    'reference_type' => 'stocktake_line',
                    'reference_id' => $line->id,
                    'reason_code' => $reason->value,
                    'note' => "Stocktake {$stocktake->public_id}: counted {$line->counted_base_qty}, expected {$review->expectedAtCount} at count.",
                    'actor_user_id' => $actorUserId,
                ]);
                $movementId = $movement->id;
                $movements++;

                $this->applyVariance($line->sku_id, $stocktake->location_id, $line->batch_id, $variance, $movementId, $now);
            }

            $this->reconcileSerials($stocktake, $review, $movementId, $now);

            $line->forceFill([
                'expected_base_qty' => $review->expectedAtCount,
                'reason_code' => $variance !== 0 ? $reason?->value : null,
                'posted_movement_id' => $movementId,
            ])->save();
        }

        $stocktake->forceFill(['status' => 'posted', 'posted_at' => $now, 'posted_by_user_id' => $actorUserId])->save();

        return ['stocktake' => $stocktake, 'written' => true, 'movements' => $movements];
    }

    private function reviewLine(Stocktake $stocktake, StocktakeLine $line): StocktakeLineReview
    {
        $onHandNow = (int) (StockLevel::identity($line->sku_id, $stocktake->location_id, $line->batch_id)->value('on_hand_base_qty') ?? 0);
        $since = (int) StockMovement::query()
            ->where('sku_id', $line->sku_id)
            ->where('location_id', $stocktake->location_id)
            ->when($line->batch_id === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $line->batch_id))
            ->where('occurred_at', '>', $line->counted_at)
            ->whereIn('movement_type', self::ON_HAND_TYPES)
            ->sum('base_qty');
        $expected = $onHandNow - $since;

        $sku = Sku::query()->findOrFail($line->sku_id);
        if (! TrackingMode::from($sku->tracking_mode)->tracksSerial()) {
            $blockers = $line->counted_base_qty - $expected + $onHandNow < 0
                ? ['Posting would take stock below zero: more has left since the count than was counted.']
                : [];

            return new StocktakeLineReview($line, $onHandNow, $expected, [], [], $blockers);
        }

        [$missing, $found, $blockers] = $this->serialDifferences($stocktake, $line, $sku);
        if (count($found) - count($missing) !== $line->counted_base_qty - $expected) {
            $blockers[] = 'Serial records and the stock level disagree for this item (04 §6.3). Report it; a count cannot absorb it.';
        }

        return new StocktakeLineReview($line, $onHandNow, $expected, $missing, $found, $blockers);
    }

    /**
     * §24.2: present at count = present now (in_stock/allocated/picked here),
     * minus received since the count, plus dispatched from here since.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function serialDifferences(Stocktake $stocktake, StocktakeLine $line, Sku $sku): array
    {
        $identity = fn () => StockSerial::query()
            ->where('sku_id', $sku->id)
            ->where('location_id', $stocktake->location_id)
            ->when($line->batch_id === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $line->batch_id));

        $presentNow = $identity()->whereIn('status', ['in_stock', 'allocated', 'picked'])->get(['serial_number', 'status', 'received_at']);
        $atCount = $presentNow
            ->filter(fn (StockSerial $s) => $s->received_at === null || $s->received_at->lessThanOrEqualTo($line->counted_at))
            ->pluck('serial_number')
            ->merge($identity()->where('status', 'dispatched')->where('dispatched_at', '>', $line->counted_at)->pluck('serial_number'))
            ->unique()
            ->values()
            ->all();

        $scanned = $line->serials()->orderBy('serial_number')->pluck('serial_number')->all();
        $missing = array_values(array_diff($atCount, $scanned));
        $found = array_values(array_diff($scanned, $atCount));
        sort($missing);
        sort($found);

        $blockers = [];
        $reserved = $presentNow->whereIn('serial_number', $missing)->whereIn('status', ['allocated', 'picked'])->pluck('serial_number')->all();
        if ($reserved !== []) {
            $blockers[] = 'Missing serials reserved to an order: '.implode(', ', $reserved).'. Short-pick the order first.';
        }

        $foundReservedElsewhere = StockSerial::query()->where('sku_id', $sku->id)->whereIn('serial_number', $found)->whereIn('status', ['allocated', 'picked'])->pluck('serial_number')->all();
        if ($foundReservedElsewhere !== []) {
            $blockers[] = 'Found serials reserved to an order elsewhere: '.implode(', ', $foundReservedElsewhere).'. Resolve the reservation first.';
        }

        return [array_map('strval', $missing), array_map('strval', $found), $blockers];
    }

    private function reconcileSerials(Stocktake $stocktake, StocktakeLineReview $review, ?int $movementId, CarbonImmutable $now): void
    {
        $line = $review->line;

        if ($review->missingSerials !== []) {
            StockSerial::query()
                ->where('sku_id', $line->sku_id)
                ->whereIn('serial_number', $review->missingSerials)
                ->where('status', 'in_stock')
                ->update(['status' => 'quarantined', 'updated_at' => $now]);
        }

        foreach ($review->foundSerials as $serialNumber) {
            $found = [
                'status' => 'in_stock',
                'location_id' => $stocktake->location_id,
                'batch_id' => $line->batch_id,
                'order_line_id' => null,
                'received_movement_id' => $movementId,
                'received_at' => $now,
                'updated_at' => $now,
            ];

            $updated = StockSerial::query()->where('sku_id', $line->sku_id)->where('serial_number', $serialNumber)->update($found);
            if ($updated === 0) {
                try {
                    $serial = StockSerial::create(['sku_id' => $line->sku_id, 'serial_number' => $serialNumber] + $found);
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) === '23505') {
                        throw new FulfilmentRejectedException('serial_conflict', "Serial {$serialNumber} was recorded by another action just now. Review again.", 'serials', [], 409);
                    }
                    throw $e;
                }
                StocktakeLineSerial::query()->where('stocktake_line_id', $line->id)->where('serial_number', $serialNumber)->update(['serial_id' => $serial->id]);
            }
        }
    }

    private function applyVariance(int $skuId, int $locationId, ?int $batchId, int $variance, int $movementId, CarbonImmutable $now): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO stock_levels (sku_id, location_id, batch_id, on_hand_base_qty, last_movement_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT ON CONSTRAINT stock_levels_identity_uq DO UPDATE SET
              on_hand_base_qty = stock_levels.on_hand_base_qty + EXCLUDED.on_hand_base_qty,
              version          = stock_levels.version + 1,
              last_movement_id = EXCLUDED.last_movement_id,
              updated_at       = EXCLUDED.updated_at
        SQL, [$skuId, $locationId, $batchId, $variance, $movementId, $now]);
    }

    private function lockCounting(Stocktake $stocktake): void
    {
        $status = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->value('status');
        if ($status !== 'open') {
            throw new FulfilmentRejectedException('stocktake_not_counting', $status === 'review' ? 'This stocktake is in review. Reopen it to count more.' : "This stocktake is {$status}.", null, ['status' => $status], 409);
        }
    }

    /** Batch where the SKU is batch-tracked, and only there (02 §7.5 invariant 1). */
    private function assertIdentity(Sku $sku, ?Batch $batch): void
    {
        $tracksBatch = TrackingMode::from($sku->tracking_mode)->tracksBatch();
        if ($tracksBatch && $batch === null) {
            throw new FulfilmentRejectedException('batch_code_required', "{$sku->sku_code} is batch-tracked: count each batch separately.", 'batch_code');
        }
        if (! $tracksBatch && $batch !== null) {
            throw new FulfilmentRejectedException('batch_not_tracked', "{$sku->sku_code} is not batch-tracked.", 'batch_code');
        }
        if ($batch !== null && $batch->sku_id !== $sku->id) {
            throw new FulfilmentRejectedException('batch_not_for_sku', "That batch is not a batch of {$sku->sku_code}.", 'batch_code');
        }
    }

    private function line(Stocktake $stocktake, Sku $sku, ?Batch $batch): ?StocktakeLine
    {
        return StocktakeLine::query()
            ->where('stocktake_id', $stocktake->id)
            ->where('sku_id', $sku->id)
            ->when($batch === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $batch?->id))
            ->lockForUpdate()
            ->first();
    }

    private function saveLine(Stocktake $stocktake, Sku $sku, ?Batch $batch, ?StocktakeLine $line, int $counted, ?int $actorUserId): StocktakeLine
    {
        $attributes = ['counted_base_qty' => $counted, 'counted_by_user_id' => $actorUserId, 'counted_at' => now()];

        if ($line !== null) {
            $line->forceFill($attributes)->save();

            return $line;
        }

        return StocktakeLine::create($attributes + ['stocktake_id' => $stocktake->id, 'sku_id' => $sku->id, 'batch_id' => $batch?->id]);
    }

    /**
     * Lines in 02 §11.1's order for one location: sku, then batch NULLS FIRST.
     *
     * @return list<StocktakeLine>
     */
    private function linesInLockOrder(Stocktake $stocktake): array
    {
        return array_values(StocktakeLine::query()
            ->where('stocktake_id', $stocktake->id)
            ->orderBy('sku_id')
            ->orderByRaw('batch_id ASC NULLS FIRST')
            ->get()
            ->all());
    }

    private function transition(Stocktake $stocktake, string $from, string $to): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $from, $to) {
            $locked = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            if ($locked->status === $to) {
                return $locked;
            }
            if ($locked->status !== $from) {
                throw new FulfilmentRejectedException('stocktake_not_open', "This stocktake is {$locked->status}.", null, ['status' => $locked->status], 409);
            }
            $locked->forceFill(['status' => $to])->save();

            return $locked;
        });
    }

    private function describe(StocktakeLineReview $review): string
    {
        $skuCode = (string) Sku::query()->whereKey($review->line->sku_id)->value('sku_code');
        $batchCode = $review->line->batch_id === null ? null : Batch::query()->whereKey($review->line->batch_id)->value('batch_code');

        return $batchCode === null ? $skuCode : "{$skuCode} batch {$batchCode}";
    }
}
