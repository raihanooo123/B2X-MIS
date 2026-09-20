<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\InvalidDeallocationException;
use App\Models\OrderLine;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reverses AllocationService's write: releases specific stock_allocations
 * rows in full, restoring the quantity they held back to stock_levels,
 * and records the reversal on the stock_movements ledger.
 *
 * Lock order is deliberately the SAME ascending
 * (sku_id, location_id, batch_id NULLS FIRST) order AllocationService
 * uses — not descending. Doc 02 §11.1 / CLAUDE.md invariant 6's whole
 * deadlock defence rests on every transaction that locks stock_levels
 * rows using one shared order; a "release in reverse" order would make
 * a concurrent allocate()+deallocate() touching the same two SKUs lock
 * them in opposite sequences — A takes X then waits on Y, B takes Y then
 * waits on X — which is the textbook circular wait §11.1 exists to rule
 * out, not a safer variant of it. If this service is ever asked to lock
 * in descending order, that request should be refused for the same
 * reason, not implemented.
 *
 * No companies lock here: deallocation only ever decreases
 * allocated_base_qty, so it carries none of the oversell risk that makes
 * allocate() lock the credit row first. It also never touched
 * credit_held_minor to begin with (§4.3 — that column is sourced from
 * credit_holds, which doesn't exist yet), so there is nothing on the
 * credit side to reverse.
 *
 * No external calls inside deallocate()'s transaction, per the same
 * CLAUDE.md invariant 6 that governs AllocationService.
 *
 * The whole transaction is wrapped in DeadlockRetryPolicy per Doc 04
 * §4.5, identically to AllocationService: retried from scratch, never
 * resumed, on SQLSTATE 40P01/55P03. InvalidDeallocationException is not
 * a QueryException, so a genuine validation failure is never retried.
 */
final class DeallocationService
{
    /**
     * @param  list<int>  $allocationIds
     * @return list<StockAllocation> the now-released allocations
     *
     * @throws InvalidDeallocationException
     */
    public function deallocate(array $allocationIds): array
    {
        if ($allocationIds === []) {
            throw new InvalidArgumentException('At least one allocation id is required.');
        }

        return (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(function () use ($allocationIds) {
                $allocations = $this->lockAndValidateAllocations($allocationIds);

                $identities = $this->uniqueSortedIdentities($allocations);
                $lockedLevels = $this->lockStockLevelsInOrder($identities);
                $this->assertReversible($identities, $lockedLevels);

                return $this->writeReleases($allocations);
            }),
            self::class,
        );
    }

    /**
     * Locks the targeted stock_allocations rows (by id, ascending) and
     * validates each is still in a releasable state. Only 'allocated' or
     * 'picked' allocations can be released — a 'dispatched' one means
     * the stock has physically left and needs a return (return_in), not
     * a deallocation; an already-'released' one has nothing left to
     * reverse.
     *
     * @param  list<int>  $allocationIds
     * @return list<StockAllocation>
     */
    private function lockAndValidateAllocations(array $allocationIds): array
    {
        $ids = array_values(array_unique($allocationIds));
        sort($ids);

        $allocations = StockAllocation::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($allocations->count() !== count($ids)) {
            $missing = array_values(array_diff($ids, $allocations->pluck('id')->all()));
            throw new InvalidDeallocationException('Allocation id(s) not found: '.implode(', ', $missing));
        }

        foreach ($allocations as $allocation) {
            if (! in_array($allocation->status, ['allocated', 'picked'], true)) {
                throw new InvalidDeallocationException(
                    "Allocation {$allocation->id} is '{$allocation->status}' and cannot be released — only 'allocated' or 'picked' allocations can be."
                );
            }
        }

        $list = [];
        foreach ($allocations as $allocation) {
            $list[] = $allocation;
        }

        return $list;
    }

    /**
     * Groups allocations sharing one (sku, location, batch) identity and
     * sums the quantity to release — mirrors
     * AllocationService::uniqueSortedIdentities() exactly, sort order
     * included.
     *
     * @param  list<StockAllocation>  $allocations
     * @return list<array{sku_id: int, location_id: int, batch_id: ?int, release: int}>
     */
    private function uniqueSortedIdentities(array $allocations): array
    {
        $identities = [];
        foreach ($allocations as $allocation) {
            $key = $allocation->sku_id.':'.$allocation->location_id.':'.($allocation->batch_id ?? 'null');
            $identities[$key] ??= [
                'sku_id' => $allocation->sku_id,
                'location_id' => $allocation->location_id,
                'batch_id' => $allocation->batch_id,
                'release' => 0,
            ];
            $identities[$key]['release'] += $allocation->base_qty;
        }

        $identities = array_values($identities);

        usort($identities, fn (array $a, array $b) => [$a['sku_id'], $a['location_id'], $a['batch_id'] ?? -1]
            <=> [$b['sku_id'], $b['location_id'], $b['batch_id'] ?? -1]);

        return $identities;
    }

    /**
     * @param  list<array{sku_id: int, location_id: int, batch_id: ?int, release: int}>  $identities
     * @return array<string, StockLevel>
     */
    private function lockStockLevelsInOrder(array $identities): array
    {
        $locked = [];
        foreach ($identities as $identity) {
            $key = $identity['sku_id'].':'.$identity['location_id'].':'.($identity['batch_id'] ?? 'null');
            $level = StockLevel::identity($identity['sku_id'], $identity['location_id'], $identity['batch_id'])
                ->lockForUpdate()
                ->first();

            if ($level === null) {
                throw new InvalidDeallocationException(
                    "No stock_levels row for sku_id={$identity['sku_id']}, location_id={$identity['location_id']} — cannot reverse an allocation against a stock identity that no longer has a level row."
                );
            }

            $locked[$key] = $level;
        }

        return $locked;
    }

    /**
     * Defensive check on the projection invariant: releasing must never
     * ask stock_levels to go below zero allocated. stock_levels_
     * allocated_chk is the belt; this is the braces — a clean domain
     * exception instead of a raw constraint-violation error.
     *
     * @param  list<array{sku_id: int, location_id: int, batch_id: ?int, release: int}>  $identities
     * @param  array<string, StockLevel>  $lockedLevels
     */
    private function assertReversible(array $identities, array $lockedLevels): void
    {
        foreach ($identities as $identity) {
            $key = $identity['sku_id'].':'.$identity['location_id'].':'.($identity['batch_id'] ?? 'null');
            $level = $lockedLevels[$key];

            if ($identity['release'] > $level->allocated_base_qty) {
                throw new InvalidDeallocationException(
                    "Cannot release {$identity['release']} units for sku_id={$identity['sku_id']}, location_id={$identity['location_id']} — only {$level->allocated_base_qty} are currently allocated."
                );
            }
        }
    }

    /**
     * @param  list<StockAllocation>  $allocations
     * @return list<StockAllocation>
     */
    private function writeReleases(array $allocations): array
    {
        $now = now();
        $released = [];

        foreach ($allocations as $allocation) {
            $allocation->update(['status' => 'released', 'released_at' => $now]);

            $movement = StockMovement::create([
                'occurred_at' => $now,
                'sku_id' => $allocation->sku_id,
                'location_id' => $allocation->location_id,
                'batch_id' => $allocation->batch_id,
                'movement_type' => 'deallocation',
                'base_qty' => -$allocation->base_qty,
                'reference_type' => 'allocation',
                'reference_id' => $allocation->id,
            ]);

            StockLevel::identity($allocation->sku_id, $allocation->location_id, $allocation->batch_id)->decrement(
                'allocated_base_qty',
                $allocation->base_qty,
                ['version' => DB::raw('version + 1'), 'last_movement_id' => $movement->id, 'updated_at' => $now]
            );

            OrderLine::where('id', $allocation->order_line_id)->decrement('allocated_base_qty', $allocation->base_qty);

            $released[] = $allocation;
        }

        return $released;
    }
}
