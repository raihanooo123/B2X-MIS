<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Models\Company;
use App\Models\OrderLine;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 02 §11.1 — "Allocation: the only path that can oversell." Implements
 * the transaction exactly as specified, in the documented global lock
 * order, with CLAUDE.md invariant 6 as a hard constraint: no external
 * call (payment, email, HTTP, waiting queue dispatch) may ever be added
 * inside allocate()'s transaction.
 *
 * Lock order:
 *   1. companies (credit) — one row, cheapest check; a rejected order
 *      holds nothing.
 *   2. collection_slots — OMITTED. That table is Phase 2 and does not
 *      exist yet, so this service currently covers the delivery/dropship
 *      fulfilment paths only, not collection.
 *   3. stock_levels — ascending (sku_id, location_id, batch_id NULLS
 *      FIRST). Consistent ordering is what prevents deadlock between two
 *      concurrent orders sharing SKUs in opposite sequence; NULLS FIRST
 *      is explicit because untracked SKUs have batch_id IS NULL and an
 *      unspecified NULL position is an unspecified lock order.
 *   4. verify availability for every line; abort the whole order (throw,
 *      transaction rolls back) if any identity falls short.
 *   5. write: stock_allocations, then stock_movements referencing it
 *      (reference_type='allocation', reference_id=<allocation id>),
 *      then the stock_levels + order_lines quantity updates.
 *
 * credit_holds (§8.4) does not exist yet (Phase 2, key-stub only). This
 * service locks the companies row and checks available credit against
 * its current columns, but never writes to credit_held_minor — that
 * column is a projection *sourced from* credit_holds (§4.3: "credit_held
 * from credit_holds at held"), and writing to it directly here would
 * fabricate a projection with no ledger backing it, exactly the kind of
 * drift §11.4 says must never be silently introduced.
 *
 * The whole transaction is wrapped in DeadlockRetryPolicy per Doc 04
 * §4.5: on SQLSTATE 40P01/55P03 it is retried from scratch, never
 * resumed. InsufficientCreditException and InsufficientStockException
 * are not QueryExceptions, so a genuine shortfall or credit rejection
 * is never mistaken for contention and never retried.
 */
final class AllocationService
{
    /**
     * @param  list<AllocationLine>  $lines
     * @return list<StockAllocation>
     *
     * @throws InsufficientCreditException
     * @throws InsufficientStockException
     */
    public function allocate(int $companyId, int $requiredCreditMinor, array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('At least one allocation line is required.');
        }

        return (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(function () use ($companyId, $requiredCreditMinor, $lines) {
                $this->lockCompanyCredit($companyId, $requiredCreditMinor);

                $identities = $this->uniqueSortedIdentities($lines);
                $lockedLevels = $this->lockStockLevelsInOrder($identities);

                $shortfalls = $this->findShortfalls($identities, $lockedLevels);
                if ($shortfalls !== []) {
                    throw new InsufficientStockException($shortfalls);
                }

                return $this->writeAllocations($lines);
            }),
            self::class,
        );
    }

    private function lockCompanyCredit(int $companyId, int $requiredCreditMinor): Company
    {
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);

        // Doc 02 §4.3: account_balance_minor does not increase the
        // credit limit — it is the customer's own unapplied credit, so
        // it is spendable on top of whatever limit headroom remains.
        $availableCreditMinor = $company->credit_limit_minor
            - $company->credit_used_minor
            - $company->credit_held_minor
            + $company->account_balance_minor;

        if ($requiredCreditMinor > $availableCreditMinor) {
            throw new InsufficientCreditException($companyId, $requiredCreditMinor, $availableCreditMinor);
        }

        return $company;
    }

    /**
     * Groups lines sharing one (sku, location, batch) identity and sums
     * their quantity — two lines can legitimately target the same
     * identity (e.g. two order lines for the same untracked SKU at the
     * same location), and the availability check must see the combined
     * requirement, not check each line against the same stock twice.
     *
     * @param  list<AllocationLine>  $lines
     * @return list<array{sku_id: int, location_id: int, batch_id: ?int, required: int}>
     */
    private function uniqueSortedIdentities(array $lines): array
    {
        $identities = [];
        foreach ($lines as $line) {
            $key = $line->identityKey();
            $identities[$key] ??= [
                'sku_id' => $line->skuId,
                'location_id' => $line->locationId,
                'batch_id' => $line->batchId,
                'required' => 0,
            ];
            $identities[$key]['required'] += $line->baseQty;
        }

        $identities = array_values($identities);

        usort($identities, fn (array $a, array $b) => [$a['sku_id'], $a['location_id'], $a['batch_id'] ?? -1]
            <=> [$b['sku_id'], $b['location_id'], $b['batch_id'] ?? -1]);

        return $identities;
    }

    /**
     * Locks rows one at a time, in the order given — the caller has
     * already sorted that order to match §11.1's required lock order.
     * A missing row (SKU never stocked at this location) is recorded as
     * null, not an error: it is handled uniformly as zero availability
     * by findShortfalls().
     *
     * @param  list<array{sku_id: int, location_id: int, batch_id: ?int, required: int}>  $identities
     * @return array<string, StockLevel|null>
     */
    private function lockStockLevelsInOrder(array $identities): array
    {
        $locked = [];
        foreach ($identities as $identity) {
            $key = $identity['sku_id'].':'.$identity['location_id'].':'.($identity['batch_id'] ?? 'null');
            $locked[$key] = StockLevel::identity($identity['sku_id'], $identity['location_id'], $identity['batch_id'])
                ->lockForUpdate()
                ->first();
        }

        return $locked;
    }

    /**
     * @param  list<array{sku_id: int, location_id: int, batch_id: ?int, required: int}>  $identities
     * @param  array<string, StockLevel|null>  $lockedLevels
     * @return list<AllocationShortfall>
     */
    private function findShortfalls(array $identities, array $lockedLevels): array
    {
        $shortfalls = [];
        foreach ($identities as $identity) {
            $key = $identity['sku_id'].':'.$identity['location_id'].':'.($identity['batch_id'] ?? 'null');
            $level = $lockedLevels[$key];
            $available = $level === null ? 0 : $level->available_base_qty;
            $required = $identity['required'];

            if ($required > $available) {
                $shortfalls[] = new AllocationShortfall(
                    $identity['sku_id'],
                    $identity['location_id'],
                    $identity['batch_id'],
                    $required,
                    $available,
                );
            }
        }

        return $shortfalls;
    }

    /**
     * @param  list<AllocationLine>  $lines
     * @return list<StockAllocation>
     */
    private function writeAllocations(array $lines): array
    {
        $now = now();
        $created = [];

        foreach ($lines as $line) {
            $allocation = StockAllocation::create([
                'order_line_id' => $line->orderLineId,
                'sku_id' => $line->skuId,
                'location_id' => $line->locationId,
                'batch_id' => $line->batchId,
                'base_qty' => $line->baseQty,
                'status' => 'allocated',
                'allocated_at' => $now,
            ]);

            $movement = StockMovement::create([
                'occurred_at' => $now,
                'sku_id' => $line->skuId,
                'location_id' => $line->locationId,
                'batch_id' => $line->batchId,
                'movement_type' => 'allocation',
                'base_qty' => $line->baseQty,
                'reference_type' => 'allocation',
                'reference_id' => $allocation->id,
            ]);

            StockLevel::identity($line->skuId, $line->locationId, $line->batchId)->increment(
                'allocated_base_qty',
                $line->baseQty,
                ['version' => DB::raw('version + 1'), 'last_movement_id' => $movement->id, 'updated_at' => $now]
            );

            OrderLine::where('id', $line->orderLineId)->increment('allocated_base_qty', $line->baseQty);

            $created[] = $allocation;
        }

        return $created;
    }
}
