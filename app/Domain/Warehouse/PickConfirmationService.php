<?php

namespace App\Domain\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Warehouse\Events\ShortPickRecorded;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockSerial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Picking — 05.5 §5.2, §5.4; 04 §6.2.
 *
 * **Serial scans are blocked, not warned.** scanSerial() moves a serial
 * from `allocated` to `picked` only if it is allocated to an order line of
 * this shipment's order, at this shipment's location. Anything else —
 * in stock, allocated to another order, already dispatched, unknown — is
 * refused. Idempotent on `(order_line_id, serial_id)` (05.5 §10): scanning
 * a serial already picked for its line is answered, not repeated.
 *
 * **Confirming** a line moves its allocation to `picked`. A serial-tracked
 * line is picked when every reserved serial has been scanned, and that
 * happens automatically on the last scan.
 *
 * **A short pick is a stock-accuracy event** (05.5 §5.4). In one
 * transaction, with the stock_levels row locked:
 *
 *   1. the allocation keeps only what was picked (`picked`), or is
 *      `released` if nothing was;
 *   2. a `deallocation` movement returns the shortfall from `allocated`,
 *      and an `adjustment` movement takes it off `on_hand` with the
 *      reason code — the stock was never there, and the ledger says so;
 *   3. unscanned reserved serials go to `quarantined` with their order
 *      line cleared: they are missing, not sellable, and not yet written
 *      off (02 §7.6; 04 §6.3's count then still holds);
 *   4. order_lines.allocated_base_qty drops by the shortfall.
 *
 * Then, in its own transaction — its keys are different stock_levels rows,
 * which cannot be locked in 02 §11.1's order while step 1's row is held —
 * the line is **re-planned**: the shortfall is allocated from other
 * eligible stock of the SKU (other batches, other sellable locations;
 * 04 §5.1's eligibility, the SKU's strategy order) through
 * AllocationService. What cannot be re-planned stays unallocated: the
 * order line is short of `base_qty`, i.e. backordered. The same
 * `(order line, location, batch)` is never re-used:
 * stock_allocations_identity_uq covers released rows too.
 *
 * Serial-tracked lines are not re-planned: allocation does not yet reserve
 * serials (ROADMAP §4, SerialSelector), so their shortfall is backordered.
 *
 * Finally ShortPickRecorded fires after commit — the discrepancy raised
 * for investigation (§5.4 step 4).
 */
final class PickConfirmationService
{
    /** 04 §5.3: a line draws from at most this many batches. */
    private const MAX_REPLAN_SOURCES = 5;

    public function __construct(
        private readonly AllocationService $allocations = new AllocationService,
    ) {}

    /**
     * Locks in the same order as short pick and dispatch — the allocation,
     * then the serial — so a scan racing either cannot deadlock with it.
     *
     * @return array{serial: StockSerial, replayed: bool}
     *
     * @throws FulfilmentRejectedException
     */
    public function scanSerial(Shipment $shipment, string $serialNumber): array
    {
        $serialNumber = trim($serialNumber);

        return (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($shipment, $serialNumber) {
            $this->assertShipmentOpen($shipment);

            $orderLineIds = OrderLine::query()->where('order_id', $shipment->order_id)->pluck('id')->all();
            $skuIds = OrderLine::query()->where('order_id', $shipment->order_id)->pluck('sku_id')->unique()->all();

            $candidates = StockSerial::query()
                ->where('serial_number', $serialNumber)
                ->whereIn('sku_id', $skuIds)
                ->orderBy('id')
                ->get();

            $mine = $candidates->first(fn (StockSerial $s) => in_array($s->order_line_id, $orderLineIds, true) && $s->location_id === $shipment->location_id);

            if ($mine === null) {
                $other = $candidates->first();
                throw new FulfilmentRejectedException(
                    'serial_not_allocated',
                    $other === null
                        ? "Serial {$serialNumber} is not one of the units reserved for this order. Scan a serial from the pick list."
                        : "Serial {$serialNumber} is {$other->status}".($other->order_line_id !== null ? ' for another order' : '').', not reserved for this order. Scan a serial from the pick list, or substitute explicitly.',
                    'serial_number',
                    ['serial_number' => $serialNumber, 'status' => $other?->status],
                );
            }

            if ($mine->status === 'picked') {
                return ['serial' => $mine, 'replayed' => true];
            }

            $allocation = StockAllocation::query()
                ->where('order_line_id', $mine->order_line_id)
                ->where('location_id', $shipment->location_id)
                ->when($mine->batch_id === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $mine->batch_id))
                ->where('status', 'allocated')
                ->lockForUpdate()
                ->first()
                ?? throw new FulfilmentRejectedException('serial_not_allocated', "Serial {$serialNumber} has no open allocation on this shipment.", 'serial_number', ['serial_number' => $serialNumber]);

            // Re-read under lock: a concurrent scan or short pick may have moved it.
            $mine = StockSerial::query()->lockForUpdate()->findOrFail($mine->id);
            if ($mine->status === 'picked') {
                return ['serial' => $mine, 'replayed' => true];
            }
            if ($mine->status !== 'allocated' || $mine->order_line_id !== $allocation->order_line_id) {
                throw new FulfilmentRejectedException('serial_not_allocated', "Serial {$serialNumber} is {$mine->status}, not waiting to be picked.", 'serial_number', ['serial_number' => $serialNumber, 'status' => $mine->status], 409);
            }

            $mine->forceFill(['status' => 'picked'])->save();

            if ($this->pickedSerialCount($allocation) >= $allocation->base_qty) {
                $allocation->forceFill(['status' => 'picked'])->save();
                $this->advanceShipment($shipment);
            } elseif (Shipment::query()->whereKey($shipment->id)->value('status') === 'pending') {
                Shipment::query()->whereKey($shipment->id)->update(['status' => 'picking', 'updated_at' => now()]);
            }

            return ['serial' => $mine, 'replayed' => false];
        }), self::class);
    }

    /**
     * @throws FulfilmentRejectedException
     */
    public function confirm(Shipment $shipment, int $allocationId): StockAllocation
    {
        return (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($shipment, $allocationId) {
            $this->assertShipmentOpen($shipment);
            $allocation = $this->lockAllocationOnShipment($shipment, $allocationId);

            if ($allocation->status === 'picked') {
                return $allocation;
            }

            $sku = Sku::query()->findOrFail($allocation->sku_id);
            if (TrackingMode::from($sku->tracking_mode)->tracksSerial()) {
                $scanned = $this->pickedSerialCount($allocation);
                if ($scanned < $allocation->base_qty) {
                    throw new FulfilmentRejectedException('serials_not_scanned', "Scan every reserved serial first: {$scanned} of {$allocation->base_qty} scanned. If units are missing, report a short pick.", 'serials', ['scanned' => $scanned, 'required' => $allocation->base_qty]);
                }
            }

            $allocation->forceFill(['status' => 'picked'])->save();
            $this->advanceShipment($shipment);

            return $allocation;
        }), self::class);
    }

    /**
     * @throws FulfilmentRejectedException
     */
    public function shortPick(Shipment $shipment, int $allocationId, int $pickedBaseQty, ShortPickReason $reason, ?int $actorUserId): ShortPickOutcome
    {
        [$allocation, $shortfall, $replannable] = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->recordShortPick($shipment, $allocationId, $pickedBaseQty, $reason, $actorUserId)),
            self::class,
        );

        $replanned = $replannable ? $this->replan($allocation, $shortfall) : 0;

        $event = new ShortPickRecorded($allocation->id, $shipment->id, $shortfall, $reason->value, $replanned, $actorUserId);
        DB::afterCommit(fn () => event($event));
        Log::warning('Short pick: stock discrepancy raised for investigation (05.5 §5.4).', [
            'allocation_id' => $allocation->id,
            'shipment' => $shipment->public_id,
            'shortfall_base_qty' => $shortfall,
            'reason' => $reason->value,
            'replanned_base_qty' => $replanned,
        ]);

        return new ShortPickOutcome($pickedBaseQty, $shortfall, $replanned);
    }

    /**
     * @return array{0: StockAllocation, 1: int, 2: bool}
     */
    private function recordShortPick(Shipment $shipment, int $allocationId, int $pickedBaseQty, ShortPickReason $reason, ?int $actorUserId): array
    {
        $this->assertShipmentOpen($shipment);
        $allocation = $this->lockAllocationOnShipment($shipment, $allocationId);

        if ($allocation->status !== 'allocated') {
            throw new FulfilmentRejectedException('line_already_picked', 'This line is already picked. A short pick is reported instead of confirming, not after it.', 'line', [], 409);
        }

        if ($pickedBaseQty < 0 || $pickedBaseQty >= $allocation->base_qty) {
            throw new FulfilmentRejectedException('invalid_picked_qty', "A short pick picks fewer than the {$allocation->base_qty} units allocated.", 'picked_qty', ['allocated_base_qty' => $allocation->base_qty]);
        }

        $sku = Sku::query()->findOrFail($allocation->sku_id);
        $mode = TrackingMode::from($sku->tracking_mode);
        if ($mode->tracksSerial() && $this->pickedSerialCount($allocation) !== $pickedBaseQty) {
            throw new FulfilmentRejectedException('picked_qty_must_match_scanned_serials', 'For a serial-tracked line, the picked quantity is the serials scanned: '.$this->pickedSerialCount($allocation).'.', 'picked_qty', ['scanned' => $this->pickedSerialCount($allocation)]);
        }

        $shortfall = $allocation->base_qty - $pickedBaseQty;
        $now = CarbonImmutable::now();

        if (StockLevel::identity($allocation->sku_id, $allocation->location_id, $allocation->batch_id)->lockForUpdate()->first() === null) {
            throw new FulfilmentRejectedException('no_stock_level', 'This allocation has no stock level row; it cannot be short-picked.', null, [], 409);
        }

        $common = [
            'occurred_at' => $now,
            'sku_id' => $allocation->sku_id,
            'location_id' => $allocation->location_id,
            'batch_id' => $allocation->batch_id,
            'reference_type' => 'allocation',
            'reference_id' => $allocation->id,
            'reason_code' => $reason->value,
            'actor_user_id' => $actorUserId,
        ];
        $note = "Short pick on shipment {$shipment->public_id}: {$pickedBaseQty} of {$allocation->base_qty} picked.";

        StockMovement::create($common + ['movement_type' => 'deallocation', 'base_qty' => -$shortfall, 'note' => $note]);
        $adjustment = StockMovement::create($common + ['movement_type' => 'adjustment', 'base_qty' => -$shortfall, 'note' => $note]);

        StockLevel::identity($allocation->sku_id, $allocation->location_id, $allocation->batch_id)->update([
            'on_hand_base_qty' => DB::raw('on_hand_base_qty - '.$shortfall),
            'allocated_base_qty' => DB::raw('allocated_base_qty - '.$shortfall),
            'version' => DB::raw('version + 1'),
            'last_movement_id' => $adjustment->id,
            'updated_at' => $now,
        ]);

        if ($mode->tracksSerial()) {
            StockSerial::query()
                ->where('order_line_id', $allocation->order_line_id)
                ->where('sku_id', $allocation->sku_id)
                ->where('location_id', $allocation->location_id)
                ->when($allocation->batch_id === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $allocation->batch_id))
                ->where('status', 'allocated')
                ->update(['status' => 'quarantined', 'order_line_id' => null, 'updated_at' => $now]);
        }

        $allocation->forceFill($pickedBaseQty === 0
            ? ['status' => 'released', 'released_at' => $now]
            : ['status' => 'picked', 'base_qty' => $pickedBaseQty])->save();

        OrderLine::query()->whereKey($allocation->order_line_id)->decrement('allocated_base_qty', $shortfall);

        $this->advanceShipment($shipment);

        return [$allocation, $shortfall, ! $mode->tracksSerial()];
    }

    /**
     * Allocate the shortfall from other eligible stock (04 §5.1), in the
     * SKU's strategy order, preferring the shipment's location. Returns
     * the quantity re-planned; the rest stays backordered.
     */
    private function replan(StockAllocation $shorted, int $shortfall): int
    {
        $sku = Sku::query()->findOrFail($shorted->sku_id);
        $mode = TrackingMode::from($sku->tracking_mode);

        $used = StockAllocation::query()
            ->where('order_line_id', $shorted->order_line_id)
            ->get(['location_id', 'batch_id'])
            ->map(fn (StockAllocation $a) => $a->location_id.':'.($a->batch_id ?? 'null'))
            ->all();

        $candidates = DB::table('stock_levels')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->leftJoin('batches', 'batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.sku_id', $sku->id)
            ->where('stock_levels.available_base_qty', '>', 0)
            ->where('locations.is_sellable', true)
            ->when(
                $mode->tracksBatch(),
                fn ($q) => $q->whereNotNull('stock_levels.batch_id')
                    ->where('batches.status', 'active')
                    ->when($sku->getAttribute('min_remaining_shelf_life_days') !== null, fn ($q) => $q->where(fn ($q) => $q
                        ->whereNull('batches.expires_on')
                        ->orWhere('batches.expires_on', '>=', CarbonImmutable::now()->addDays((int) $sku->getAttribute('min_remaining_shelf_life_days'))->toDateString()))),
                fn ($q) => $q->whereNull('stock_levels.batch_id'),
            )
            ->orderByRaw('(stock_levels.location_id = ?) DESC', [$shorted->location_id])
            ->when($sku->getAttribute('allocation_strategy') === 'fefo', fn ($q) => $q->orderByRaw('batches.expires_on ASC NULLS LAST'))
            ->when($sku->getAttribute('allocation_strategy') === 'lifo', fn ($q) => $q->orderByDesc('batches.id'), fn ($q) => $q->orderBy('batches.id'))
            ->orderBy('stock_levels.location_id')
            ->get(['stock_levels.location_id', 'stock_levels.batch_id', 'stock_levels.available_base_qty']);

        $lines = [];
        $remaining = $shortfall;
        foreach ($candidates as $candidate) {
            $key = $candidate->location_id.':'.($candidate->batch_id ?? 'null');
            if ($remaining === 0 || count($lines) === self::MAX_REPLAN_SOURCES || in_array($key, $used, true)) {
                continue;
            }
            $take = min($remaining, (int) $candidate->available_base_qty);
            $lines[] = new AllocationLine($shorted->order_line_id, $sku->id, (int) $candidate->location_id, $candidate->batch_id === null ? null : (int) $candidate->batch_id, $take);
            $remaining -= $take;
        }

        if ($lines === []) {
            return 0;
        }

        try {
            // No credit re-check: the order's hold already covers this line.
            $this->allocations->allocate(null, 0, $lines);
        } catch (InsufficientStockException) {
            // Taken by a concurrent order since the read: the shortfall stays backordered.
            return 0;
        }

        return $shortfall - $remaining;
    }

    private function assertShipmentOpen(Shipment $shipment): void
    {
        $status = Shipment::query()->whereKey($shipment->id)->value('status');
        if (! in_array($status, FulfilmentRules::OPEN_SHIPMENT_STATUSES, true)) {
            throw new FulfilmentRejectedException('shipment_not_open', "This shipment is {$status}.", null, ['status' => $status], 409);
        }
    }

    private function lockAllocationOnShipment(Shipment $shipment, int $allocationId): StockAllocation
    {
        $allocation = FulfilmentRules::activeAllocations($shipment->order_id, $shipment->location_id)
            ->where('stock_allocations.id', $allocationId)
            ->lock('FOR UPDATE OF stock_allocations')
            ->first();

        return $allocation ?? throw new FulfilmentRejectedException('line_not_on_shipment', 'That line is not on this pick list.', 'line', [], 404);
    }

    private function pickedSerialCount(StockAllocation $allocation): int
    {
        return StockSerial::query()
            ->where('order_line_id', $allocation->order_line_id)
            ->where('sku_id', $allocation->sku_id)
            ->where('location_id', $allocation->location_id)
            ->when($allocation->batch_id === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $allocation->batch_id))
            ->where('status', 'picked')
            ->count();
    }

    /** `picking` once anything is picked; `picked` when the whole list is. */
    private function advanceShipment(Shipment $shipment): void
    {
        $unpicked = FulfilmentRules::activeAllocations($shipment->order_id, $shipment->location_id)
            ->where('stock_allocations.status', 'allocated')
            ->exists();

        $shipment->forceFill($unpicked
            ? ['status' => 'picking']
            : ['status' => 'picked', 'picked_at' => $shipment->picked_at ?? now()])->save();
    }
}
