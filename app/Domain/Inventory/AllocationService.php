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
 * credit_holds (05.2 §7.1) now exists, but this service still never
 * writes `credit_held_minor` itself — that write, and the credit_holds
 * insert, happen in the caller's transaction (CheckoutService) *after*
 * this method returns, because doc 02 §11.1's own step order puts
 * "INSERT credit_holds" at step 6, after stock is verified and written
 * (step 5), not alongside the credit lock at step 1. What this method
 * DOES do at step 1 is the pass/fail *check* — "credit locked first...
 * failing it avoids taking any stock locks at all" (05.2 §8.2) — against
 * the company's current `credit_held_minor` (which already reflects
 * every earlier order's hold), so a second concurrent order against the
 * same company sees the first order's hold once it has one, via the
 * `FOR UPDATE` lock's serialisation.
 *
 * `companyId` is nullable: doc 05.2 has no provision for a company-less
 * order because 05.2 is scoped to trade accounts, but this platform also
 * sells to the public (CLAUDE.md, "the system will also sell to the
 * public"). A null companyId means "no credit is at stake" — the
 * companies-row lock and the credit check are both skipped entirely, and
 * $requiredCreditMinor is ignored. Deciding *when* a company-having order
 * should also skip the credit check (e.g. it is paying by card, not on
 * account) is the caller's business-rule call, not this service's — see
 * CheckoutService.
 *
 * allocateWithinTransaction() is the actual step 1-5 sequence, with no
 * transaction or retry wrapper of its own, so a caller that already owns
 * an outer transaction (CheckoutService, composing credit + stock + order
 * creation into ONE retryable unit per doc 04 §4.5's "the whole
 * transaction is retried, never resumed") can call it directly without
 * nesting a second transaction inside the first. allocate() remains the
 * standalone entry point for callers with no other locks to take — it
 * wraps allocateWithinTransaction() in its own DB::transaction() and
 * DeadlockRetryPolicy, exactly as before.
 *
 * InsufficientCreditException and InsufficientStockException are not
 * QueryExceptions, so a genuine shortfall or credit rejection is never
 * mistaken for contention and never retried.
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
    public function allocate(?int $companyId, int $requiredCreditMinor, array $lines): array
    {
        return (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->allocateWithinTransaction($companyId, $requiredCreditMinor, $lines)),
            self::class,
        );
    }

    /**
     * The step 1-5 sequence itself, with no transaction/retry wrapper —
     * see the class docblock for why this is split from allocate().
     * MUST be called from inside an existing transaction.
     *
     * @param  list<AllocationLine>  $lines
     * @return list<StockAllocation>
     *
     * @throws InsufficientCreditException
     * @throws InsufficientStockException
     */
    public function allocateWithinTransaction(?int $companyId, int $requiredCreditMinor, array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('At least one allocation line is required.');
        }

        if ($companyId !== null) {
            $this->lockCompanyCredit($companyId, $requiredCreditMinor);
        }

        $identities = $this->uniqueSortedIdentities($lines);
        $lockedLevels = $this->lockStockLevelsInOrder($identities);

        $shortfalls = $this->findShortfalls($identities, $lockedLevels);
        if ($shortfalls !== []) {
            throw new InsufficientStockException($shortfalls);
        }

        return $this->writeAllocations($lines);
    }

    /**
     * The companies-row lock and credit check alone, with no stock
     * involved — for a caller (CheckoutService) whose order contains no
     * stock-tracked lines at all (every SKU `is_stock_tracked = false`)
     * but still needs the credit gate applied, since 05.2 §8.1's credit
     * check does not depend on the order containing any allocatable
     * stock. MUST be called from inside an existing transaction, exactly
     * like allocateWithinTransaction().
     *
     * @throws InsufficientCreditException
     */
    public function lockAndCheckCredit(int $companyId, int $requiredCreditMinor): void
    {
        $this->lockCompanyCredit($companyId, $requiredCreditMinor);
    }

    private function lockCompanyCredit(int $companyId, int $requiredCreditMinor): Company
    {
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);

        // Doc 05.4 §7.5A: "It does not increase available credit. A
        // customer with a £10,000 limit and a £500 balance can place
        // £10,500 of orders, because the £500 is already their money."
        // account_balance_minor is applied as PAYMENT against the order
        // total once it is known (§7.5A "Applying balance at checkout"
        // — not yet built here), never folded into the credit gate: a
        // customer spending their own £500 balance is not the same
        // event as the business extending them £500 more exposure, and
        // adding it here would let the same balance be counted twice —
        // once as extra headroom at the credit check, again when it's
        // actually applied as payment.
        $availableCreditMinor = $company->credit_limit_minor
            - $company->credit_used_minor
            - $company->credit_held_minor;

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
