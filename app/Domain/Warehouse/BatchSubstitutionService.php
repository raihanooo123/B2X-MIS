<?php

namespace App\Domain\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\MovementAttribution;
use App\Domain\Warehouse\Events\BatchSubstituted;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\Batch;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Batch substitution — 05.5 §5.3. Permitted, because the FEFO batch may be
 * buried behind a pallet; never silent, because the delivery note and the
 * recall trace depend on which batch left.
 *
 * One transaction, composed from the existing services rather than new
 * movement logic:
 *
 *   1. the allocation row, FOR UPDATE — it must still be `allocated`;
 *   2. both stock_levels rows, the old batch's and the new one's, locked
 *      up front in 02 §11.1's order (ascending batch_id, same SKU and
 *      location), so neither service below takes a lock out of order;
 *   3. DeallocationService releases the old allocation (`deallocation`
 *      movement);
 *   4. AllocationService allocates the same quantity from the new batch
 *      (`allocation` movement, a new stock_allocations row), checking
 *      availability under the lock.
 *
 * **The audit record** (decided 2026-09-25): until `audit_log` exists
 * (02 §15, ROADMAP §0.6) both movements carry the actor, reason code
 * `batch_substitution`, and a note naming both batches and the picker's
 * reason. BatchSubstituted fires after commit, for the audit writer to
 * listen to once there is one.
 *
 * Refused: a line already picked (substitute before picking); a SKU that
 * is not batch-tracked; a serial-tracked SKU, whose reserved serials would
 * need re-reserving, which allocation does not yet do (ROADMAP §4); a
 * batch that is not active, is for another SKU, fails the SKU's
 * remaining-shelf-life rule (04 §5.1), or was already allocated to this
 * line — stock_allocations_identity_uq covers released rows too.
 */
final class BatchSubstitutionService
{
    public const REASON_CODE = 'batch_substitution';

    public function __construct(
        private readonly DeallocationService $deallocations = new DeallocationService,
        private readonly AllocationService $allocations = new AllocationService,
    ) {}

    /**
     * @return StockAllocation the new allocation
     *
     * @throws FulfilmentRejectedException
     */
    public function substitute(Shipment $shipment, int $allocationId, int $newBatchId, string $reason, ?int $actorUserId): StockAllocation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FulfilmentRejectedException('substitution_reason_required', 'Say why the allocated batch is not being picked.', 'reason');
        }

        [$released, $allocated, $fromBatch] = (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($shipment, $allocationId, $newBatchId, $reason, $actorUserId) {
            $status = Shipment::query()->whereKey($shipment->id)->value('status');
            if (! in_array($status, FulfilmentRules::OPEN_SHIPMENT_STATUSES, true)) {
                throw new FulfilmentRejectedException('shipment_not_open', "This shipment is {$status}.", null, ['status' => $status], 409);
            }

            $allocation = FulfilmentRules::activeAllocations($shipment->order_id, $shipment->location_id)
                ->where('stock_allocations.id', $allocationId)
                ->lock('FOR UPDATE OF stock_allocations')
                ->first()
                ?? throw new FulfilmentRejectedException('line_not_on_shipment', 'That line is not on this pick list.', 'line', [], 404);

            if ($allocation->status !== 'allocated') {
                throw new FulfilmentRejectedException('line_already_picked', 'This line is already picked; substitute a batch before picking it.', 'line', [], 409);
            }

            $sku = Sku::query()->findOrFail($allocation->sku_id);
            $mode = TrackingMode::from($sku->tracking_mode);
            if (! $mode->tracksBatch() || $allocation->batch_id === null) {
                throw new FulfilmentRejectedException('not_batch_tracked', "{$sku->sku_code} is not batch-tracked; there is no batch to substitute.", 'batch_code');
            }
            if ($mode->tracksSerial()) {
                throw new FulfilmentRejectedException('serial_substitution_not_supported', "{$sku->sku_code} is serial-tracked: its reserved serials cannot be re-reserved yet. Report a short pick instead.", 'batch_code');
            }

            $from = Batch::query()->findOrFail($allocation->batch_id);
            $to = $this->eligibleBatch($sku, $newBatchId, $from);

            if (StockAllocation::query()->where('order_line_id', $allocation->order_line_id)->where('location_id', $allocation->location_id)->where('batch_id', $to->id)->exists()) {
                throw new FulfilmentRejectedException('batch_already_used_for_line', "Batch {$to->batch_code} was already allocated to this line once; it cannot be allocated to it again.", 'new_batch_code');
            }

            // 02 §11.1: both rows, ascending batch_id, before either service locks one.
            foreach ([min($from->id, $to->id), max($from->id, $to->id)] as $batchId) {
                StockLevel::identity($sku->id, $allocation->location_id, $batchId)->lockForUpdate()->first();
            }

            $attribution = new MovementAttribution(
                $actorUserId,
                self::REASON_CODE,
                "Batch {$from->batch_code} → {$to->batch_code} on shipment {$shipment->public_id}: {$reason}",
            );

            $released = $this->deallocations->deallocateWithinTransaction([$allocation->id], $attribution)[0];

            try {
                $allocated = $this->allocations->allocateWithinTransaction(null, 0, [
                    new AllocationLine($allocation->order_line_id, $sku->id, $allocation->location_id, $to->id, $allocation->base_qty),
                ], $attribution)[0];
            } catch (InsufficientStockException $e) {
                throw new FulfilmentRejectedException('substitute_batch_insufficient', "Batch {$to->batch_code} does not have {$allocation->base_qty} units available here.", 'new_batch_code', ['required_base_qty' => $allocation->base_qty]);
            }

            if ($allocation->suggested_bin_id !== null) {
                // Nothing better is known; the picker records what they took.
                $allocated->forceFill(['suggested_bin_id' => null])->save();
            }

            return [$released, $allocated, $from];
        }), self::class);

        $event = new BatchSubstituted($released->id, $allocated->id, $fromBatch->id, (int) $allocated->batch_id, $actorUserId, $reason);
        DB::afterCommit(fn () => event($event));

        return $allocated;
    }

    private function eligibleBatch(Sku $sku, int $batchId, Batch $from): Batch
    {
        $to = Batch::query()->find($batchId);

        if ($to === null || $to->sku_id !== $sku->id) {
            throw new FulfilmentRejectedException('batch_not_for_sku', "That batch is not a batch of {$sku->sku_code}.", 'new_batch_code');
        }
        if ($to->id === $from->id) {
            throw new FulfilmentRejectedException('same_batch', "Batch {$to->batch_code} is the one already allocated.", 'new_batch_code');
        }
        if ($to->status !== 'active') {
            throw new FulfilmentRejectedException('batch_not_eligible', "Batch {$to->batch_code} is {$to->status} and cannot be allocated.", 'new_batch_code', ['status' => $to->status]);
        }

        $minDays = $sku->getAttribute('min_remaining_shelf_life_days');
        if ($minDays !== null && $to->expires_on !== null && $to->expires_on->toDateString() < CarbonImmutable::now()->addDays((int) $minDays)->toDateString()) {
            throw new FulfilmentRejectedException('batch_not_eligible', "Batch {$to->batch_code} expires {$to->expires_on->toDateString()}, inside this SKU's minimum remaining shelf life of {$minDays} days.", 'new_batch_code');
        }

        return $to;
    }
}
