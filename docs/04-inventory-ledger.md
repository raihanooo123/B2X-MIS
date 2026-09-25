# Inventory & Stock Ledger Specification

**B2B Wholesale & Distribution Platform**

| | |
|---|---|
| Document | 04 — Inventory & Stock Ledger Spec |
| Status | Draft for review — **swept for PostgreSQL 16** |
| Depends on | 02 — Domain Model & ERD (§7 Inventory), 03 — Pricing Engine Spec (cost snapshots) |
| Feeds into | 05 — Module Specs (goods-in, picking, stocktake, PO/containers) |

---

## 1. Purpose

Stock is **authoritative in this system**. There is no upstream ERP to defer to and no reconciliation against a third party. If this component is wrong, the business ships goods it does not have or refuses orders it could have filled, and no external system will correct it.

This document specifies how stock is recorded, reserved, consumed and verified.

Three properties must hold under all conditions, including concurrent load, failed requests and process crashes:

1. **No overselling.** The system never allocates stock it does not have.
2. **Full auditability.** Every change in quantity is attributable to a cause, an actor and a moment.
3. **Recoverability.** Every derived number can be rebuilt from the record of what happened.

---

## 2. Core principles

### 2.1 The ledger is the truth

`stock_movements` is **append-only**. No `UPDATE`, no `DELETE`, under any circumstance including administrative correction.

A mistake is corrected by writing a compensating movement with a reason code. The original stays. This is not bureaucratic caution — it is the only way to answer *"why does this number look wrong?"*, which is the question you will be asked and which a mutable counter cannot answer.

Doc 02 §7.4 enforces this structurally: the table carries no foreign keys and a `(id, occurred_at)` primary key, so it can be partitioned by time without a rebuild.

### 2.2 Levels are a projection

`stock_levels` is derived. It exists solely because computing `SUM(base_qty)` across a growing ledger on every product page is not viable.

It is maintained **in the same transaction** as the movement that changes it, and it is **fully rebuildable**:

```sql
-- rebuild for one (sku, location, batch); batch_id IS NULL for untracked stock
SELECT coalesce(sum(base_qty), 0) FROM stock_movements
WHERE  sku_id = :sku AND location_id = :loc
  AND  batch_id IS NOT DISTINCT FROM :batch
  AND  movement_type IN ('goods_in','dispatch','return_in','adjustment',
                         'stocktake','transfer_in','transfer_out','write_off');
```

Allocation movement types are excluded from the on-hand sum — they move stock between *available* and *allocated*, not into or out of the building. §3 makes this explicit per type.

`IS NOT DISTINCT FROM` is the null-safe comparison the rebuild needs, because `batch_id IS NULL` now genuinely means "not batch-tracked" (02 §7.3). The MySQL edition compared against a sentinel `0` and so needed no null handling — one of the few places the sentinel was simpler, and not nearly enough to justify the rest of its cost.

### 2.3 Allocation reserves, dispatch consumes

Placing an order does **not** reduce `on_hand_base_qty`. It increases `allocated_base_qty`. The goods are still physically present and still on the shelf.

```
available_base_qty = on_hand_base_qty − allocated_base_qty      (generated column)
```

Stock leaves `on_hand` only at dispatch. This distinction is what makes a stock count on the warehouse floor match the system: an order picked but not yet shipped is still in the building, and `on_hand` still says so.

### 2.4 Traceability is per-batch, per-serial

Every level row and every movement is keyed to a batch (`0` = untracked sentinel). Serial-tracked SKUs additionally carry one `stock_serials` row per physical unit. Doc 02 §7.5–7.6.

---

## 3. Movement type catalogue

Signs are from the perspective of the location. The **Touches** column states which projection fields change — this table is the authoritative reference for the projection updater.

| Type | Sign | Touches | Reference | Trigger |
|---|---|---|---|---|
| `goods_in` | + | `on_hand` | `goods_receipt_line` (02 §23.3) | Receipt booked in |
| `allocation` | 0 | `allocated` +qty | `allocation` | Order confirmed |
| `deallocation` | 0 | `allocated` −qty | `allocation` | Order cancelled, or reaper released |
| `dispatch` | − | `on_hand` −qty, `allocated` −qty | `shipment` | Goods shipped or collected |
| `return_in` | + | `on_hand` | `rma` | Return received and inspected |
| `adjustment` | ± | `on_hand` | manual | Damage, loss, found stock, correction |
| `stocktake` | ± | `on_hand` | `stocktake` | Count variance posted |
| `transfer_out` | − | `on_hand` | `transfer` | Leaving this location |
| `transfer_in` | + | `on_hand` | `transfer` | Arriving at this location |
| `write_off` | − | `on_hand` | manual / `batch` | Expired, recalled, destroyed |

Rules:

- `allocation` and `deallocation` carry `base_qty` with the sign of their effect on `allocated`, and are excluded from the `on_hand` rebuild sum. They are in the ledger because *"who reserved this and when"* is an audit question.
- `dispatch` is the only type that decrements both fields. It must be written atomically with the allocation status change, or `allocated` leaks.
- `adjustment`, `stocktake` and `write_off` **require** a `reason_code`. Enforced by application validation; the codes are a closed list in the goods-in module spec.
- A transfer is **two** movements in one transaction, never one movement with two locations.

---

## 4. The allocation transaction

The single most correctness-critical routine in the application.

### 4.1 Contract

```php
AllocationResult allocate(Order $order): AllocationResult;
```

Atomic across the whole order. Either every line is fully allocated, or nothing is and the order is rejected or backordered. **Partial allocation of an order is never silently accepted** — a buyer who ordered 10 cases and receives 4 without being told has a worse experience than one who is told at checkout.

### 4.2 Sequence

```
BEGIN;                                    -- READ COMMITTED (Doc 02 §11.2)

  1. Load order lines, ordered by sku_id ASC
  2. For each line, determine candidate (location, batch) sources:
       tracking_mode = 'none'  → single row, batch_id IS NULL
       batch tracked           → §5 selection strategy
  3. Collect the full set of (sku_id, location_id, batch_id) keys
  4. SELECT ... FOR UPDATE on stock_levels for those keys,
       ORDER BY sku_id, location_id, batch_id NULLS FIRST
  5. Verify: Σ available >= base_qty for every line
       any shortfall → ROLLBACK, return Shortfall(lines)
  6. For each line, for each source used:
       UPDATE stock_levels SET allocated_base_qty = allocated_base_qty + n,
                               version = version + 1
       INSERT stock_allocations (order_line_id, location_id, batch_id, base_qty)
       INSERT stock_movements   (movement_type = 'allocation', base_qty = n)
  7. Serial-tracked lines → §6 serial reservation
  8. UPDATE order_lines.allocated_base_qty
  9. UPDATE orders.status = 'confirmed'

COMMIT;
```

### 4.3 Lock ordering — the deadlock rule

**Locks are always acquired in ascending `(sku_id, location_id, batch_id NULLS FIRST)` order.** No exceptions, no shortcuts, no code path that locks in the order lines happen to arrive in.

`NULLS FIRST` is specified explicitly because PostgreSQL orders NULLs **last** in ascending order by default, and untracked SKUs carry `batch_id IS NULL`. An unspecified NULL position is an unspecified lock order, which is the whole class of bug this rule exists to remove. The full global order, including the credit and collection-slot rows, is in 02 §11.1.

Two concurrent orders each containing SKUs 100 and 200, one locking 100 then 200 and the other 200 then 100, deadlock. Consistent global ordering removes the possibility entirely rather than mitigating it. This is cheap to do and impossible to retrofit safely, because any single code path that ignores it reintroduces the whole class of failure.

Step 1 sorts. Step 4 sorts. Both are asserted by a test that inspects the generated SQL.

### 4.4 No external calls inside the transaction

The transaction holds row locks for its duration, so lock duration bounds throughput. Inside it: no payment gateway call, no email, no HTTP request, no queue dispatch that waits, no logging to a remote sink.

- Payment **authorisation** happens before, and is captured after.
- Side effects (confirmation email, warehouse notification, search reindex) are queued jobs dispatched **after commit**, via `DB::afterCommit()`.

### 4.5 Deadlock retry

Despite §4.3, a deadlock or lock timeout can still surface — a long-running report holding a row, or `lock_timeout` firing under load. PostgreSQL takes no gap locks, so the *class* of deadlock between transactions touching no common row is gone; what remains is genuine contention on the same rows. The allocation service retries on deadlock and lock-timeout conditions:

- 3 attempts, exponential backoff with jitter (50 ms, 150 ms, 400 ms ± 30%). Retried on SQLSTATE `40P01` (deadlock detected) and `55P03` (lock not available)
- The whole transaction is retried, never resumed
- Exhausted retries surface a user-facing "please try again" and an alert, not a silent failure
- Retry count is a monitored metric; a sustained rise means §4.3 has been violated somewhere

### 4.6 Shortfall handling

| Condition | Behaviour |
|---|---|
| Available < requested, `allow_backorder = 0` | Reject the order. Return per-line shortfall so the cart can show exactly what and how much |
| Available < requested, `allow_backorder = 1` | Allocate what exists, create a backorder for the remainder, flag the line |
| SKU `is_stock_tracked = 0` | No allocation, no movement. Always available |
| Batch-tracked, no eligible batch | Reject with `NoEligibleBatch`, distinct from a plain shortfall — stock may exist but be quarantined or too near expiry |

### 4.7 Collection orders and bin granularity

Two settled decisions, both affecting this transaction.

**Collection orders allocate at placement, exactly like delivery orders.** A booked collection increments `allocated_base_qty` the moment the order is confirmed, not when the customer arrives at the counter.

The reason is walk-in trade. A cash-and-carry sells the same stock off the shelf to whoever is standing there. Stock reserved for a booked collection but not marked allocated will be sold out from under it, and the customer arrives to find their order cannot be filled. Allocating at placement makes the reservation real and visible to the walk-in till — which is the whole point of holding authoritative stock rather than deferring to a till system.

Consequences:

- `orders.fulfilment_type = 'collection'` follows the identical §4.2 sequence. No separate code path, no separate correctness argument.
- The stale-allocation reaper (§9) applies to unpaid collection orders on the same hold window as delivery. A no-show does not hold stock indefinitely.
- Collection completes with a `dispatch` movement, same as a shipment. "Dispatched" means "left the building", whether by van or by the customer's own car.

**Allocation granularity is `(sku_id, location_id, batch_id)`. Bins are advisory only.**

`stock_allocations.suggested_bin_id` is a hint printed on the picking slip. It is never locked, never verified, and a picker taking goods from a different bin is not an error.

The rejected alternative was bin-level allocation — locking bin rows so the system knows precisely which shelf a reservation came from. Rejected because it adds a second lock tier inside the transaction that bounds checkout throughput, during exactly the peak periods when a cash-and-carry cannot afford checkout latency. The gain is operational tidiness; the cost is contention on the critical path. Bins earn their place on the pick list, not in the lock set.

Consequences:

- Pick lists group by `suggested_bin_id` to minimise walking, via the read-side index `stock_allocations_bin_idx`. No write-side cost.
- `stock_levels` is not keyed by bin, so bin-level figures are not authoritative and are never reported as though they were.
- Bin-level allocation stays addable later: a new table joined to `stock_allocations`, not a change to the primary key of `stock_levels`. Additive, per the standing test in 02 §13.


---

## 5. Batch selection

Applies when `skus.tracking_mode` is `batch` or `batch_and_serial`.

### 5.1 Eligibility filter

A batch is a candidate only if **all** hold:

1. `batches.status = 'active'` — excludes `quarantined`, `expired`, `recalled`, `depleted`
2. `stock_levels.available_base_qty > 0` for that `(sku, location, batch)`
3. If `skus.min_remaining_shelf_life_days` is set: `expires_on >= today + that many days`
4. Location is `is_sellable = 1`

Point 1 excludes by **query** — and that query predicate is now literally the index predicate: `batches_fefo_idx` is partial on `status = 'active'`, so ineligible batches are not in the index at all. A recalled batch physically exists and must stay visible and auditable, never zeroed — see Doc 02 §7.5 invariant 4.

Point 3 matters commercially: a customer on a 90-day contract must not receive stock expiring next week. Getting this wrong produces a return, a credit note and a lost account.

### 5.2 Strategies

`skus.allocation_strategy`:

| Value | Order by | Use |
|---|---|---|
| `fefo` | `expires_on ASC, id ASC` | Anything with an expiry. First-expired-first-out minimises write-off |
| `fifo` | `id ASC` | Non-perishable batch-tracked stock; receipt order |
| `lifo` | `id DESC` | Rare; cost-driven scenarios |
| `none` | n/a | Not batch tracked |

Doc 02 §7.5 index `batches_fefo_idx (sku_id, expires_on NULLS LAST, id)` — partial on `status = 'active'`, with `INCLUDE (batch_code, unit_cost_e4)` — serves FEFO as an index-only scan with no sort. `id` is the tiebreak for same-day expiries, making selection deterministic and therefore testable.

**`FOR UPDATE … SKIP LOCKED` for candidate selection.** Where several concurrent orders compete for one SKU's batches, `SKIP LOCKED` lets each transaction take the next unlocked eligible batch instead of queueing behind the first (02 §11.1). This is safe because batch *choice* is a policy preference, not a correctness requirement: taking the second-oldest batch when the oldest is locked by an in-flight order is acceptable FEFO behaviour, and quantity correctness still comes from the `FOR UPDATE` on the level row. There is no MySQL equivalent, and on fast-moving batch-tracked lines it materially improves throughput.

### 5.3 Splitting across batches

A line **is routinely satisfied from more than one batch**: 432 units as 300 from batch A and 132 from batch B. Consequences already built into the schema:

- One `stock_allocations` row per `(order_line, location, batch)` — which is why its unique key includes `batch_id`, declared `UNIQUE NULLS NOT DISTINCT` so two NULL-batch rows for one line are also rejected (Doc 02 §7.4). Under MySQL that null-safety came only from the sentinel; here the constraint states it
- One `stock_movements` row per batch consumed
- One `shipment_line_batches` row per batch dispatched, so the delivery note and the recall trace are both accurate

Batches are consumed greedily in strategy order until the line is satisfied. The count of batches per line is capped (default 5) to avoid a line drawing from twenty near-empty batches; exceeding the cap raises a warning for manual picking rather than failing.

---

## 6. Serial selection

Applies when `tracking_mode` is `serial` or `batch_and_serial`.

### 6.1 Reservation at allocation vs capture at pick

**Serials are reserved at allocation, not chosen at pick.** The alternative — allocating quantity and letting the picker grab any unit — means the system cannot answer "where is unit X" until dispatch, and a warranty claim arriving before then is unanswerable.

```sql
-- inside the allocation transaction, after the level lock
SELECT id FROM stock_serials
WHERE  sku_id = :sku AND status = 'in_stock'
  AND  location_id = :loc AND batch_id = :batch
ORDER BY id ASC
LIMIT  :qty
FOR UPDATE;
```

Uses `stock_serials_pick_idx`. Selected rows move to `status = 'allocated'` with `order_line_id` set.

### 6.2 Serial status machine

```
expected ──receipt──▶ in_stock ──allocate──▶ allocated ──pick──▶ picked
                          ▲                      │                 │
                          │                  deallocate         dispatch
                          │                      │                 │
                          └──────────────────────┘                 ▼
                          ▲                                   dispatched
                          │                                        │
                          └──────────── return_in ◀────────────────┘
                                                                   │
in_stock / quarantined / written_off  ◀── inspection ──────────────┘
```

- A picker scanning a serial not in `allocated` for that order is **blocked**, not warned. Substitution requires an explicit reassignment action that writes to the audit log.
- `written_off` is terminal.
- `stock_serials_assigned_chk` (Doc 02 §7.6) makes a dispatched serial with no order line structurally impossible.

### 6.3 The count invariant

For any serial-tracked `(sku_id, location_id, batch_id)`:

```
stock_levels.on_hand_base_qty
  = COUNT(stock_serials WHERE status IN ('in_stock','allocated','picked'))
```

Serial rows and quantity levels are two representations of one physical reality. The system must never be permitted to believe both independently. Verified nightly (§9); any drift is a P1.

---

## 7. Operational flows

### 7.1 Goods in

```
1. Select source: purchase order line, container, or manual receipt
2. Enter quantity in packs → converted to base_qty immediately
3. Batch tracked?  → capture batch_code, expires_on (mandatory if requires_expiry)
                     create or match the batches row
4. Serial tracked? → capture or import serials; pre-registered 'expected'
                     serials transition to 'in_stock'
5. Assign location and bin
6. TRANSACTION: INSERT stock_movements (goods_in) + UPDATE stock_levels
                + INSERT/UPDATE stock_serials
                + UPDATE purchase_order_lines.received_base_qty
                + INSERT sku_costs if landed cost supplied
7. AFTER COMMIT: fire BackInStock notifications; reindex search
```

Rules:

- A batch-tracked SKU received **without** a batch code is rejected at the screen. Not accepted-then-corrected: corrected batch data is untrustworthy for a recall, which defeats the purpose.
- `requires_expiry = 1` makes `expires_on` mandatory.
- Over-receipt against a PO line is permitted with a variance reason, because it happens.
- Cost entry at receipt is what populates `sku_costs` and therefore margin (Doc 03 §11).
- **Amended 2026-09-25 (02 §23).** Each receipt entry is a `goods_receipt_lines` row, idempotent on `(goods_receipt_id, purchase_order_line_id, client_token)`, and its `goods_in` movement references that row. `incoming_base_qty` decrements on the `(sku, location, NULL)` row, and `on_hand_base_qty` increments on the received batch's row. For a batch-tracked SKU these are different rows (02 §23.5), and §9's reconciliation sums across them.

### 7.2 Pick, pack, dispatch

```
Allocation → pick list → picked → packed → dispatched
```

- Pick lists are generated per shipment, grouped by bin location to minimise walking, and show the **specific batch and serials** reserved.
- Picking a different batch than allocated requires an explicit reallocation, which rewrites the allocation row and both movements. It is logged. It is not silently permitted, because the delivery note and recall trace depend on it.
- Dispatch is the atomic moment:

```
TRANSACTION:
  INSERT stock_movements (dispatch, −qty)         -- decrements on_hand AND allocated
  UPDATE stock_levels    (on_hand −n, allocated −n)
  UPDATE stock_allocations.status = 'dispatched'
  UPDATE stock_serials   status = 'dispatched'
  UPDATE order_lines.dispatched_base_qty
  INSERT shipment_lines (+ shipment_line_batches / _serials)
  RECOMPUTE orders.status → part_dispatched | dispatched
COMMIT;
AFTER COMMIT: dispatch email, invoice generation, courier manifest
```

- Partial dispatch is supported: `order_lines.dispatched_base_qty < base_qty` with the remainder still allocated. Order status becomes `part_dispatched`.
- **Lock order, as built 2026-09-25** (`DispatchService`): `orders` → `shipments` → `stock_allocations` (ascending id) → `stock_levels` (02 §11.1 order) → `stock_serials`. The `orders` lock serialises dispatch against cancellation (05.5 §13 W2). Dispatch moves no credit, so it takes no `companies` lock. Invoicing, which does move credit, runs after commit in its own transaction, locking `companies` → `orders`. Picking, short pick and batch substitution lock `stock_allocations` → `stock_levels` → `stock_serials`, the same relative order.

### 7.3 Returns inbound

Goods arriving under an RMA do **not** re-enter stock on receipt. They enter inspection:

```
RMA received → inspected → (restock | quarantine | write_off)
```

Only `restock` writes a `return_in` movement. Batch-tracked returns must be attributed to their original batch (recoverable from the dispatch trace); if it cannot be established, the goods are quarantined, not guessed into a batch.

Restocking fees and non-refundable exclusions are commercial logic in the RMA module spec, driven by `skus.is_refundable` and `non_refundable_reason` (Doc 02 §5.5).

### 7.4 Adjustments and stocktake

- **Adjustment:** single-SKU correction with a mandatory reason code and an actor. Uses optimistic concurrency via `stock_levels.version`, not `FOR UPDATE` — an admin edit colliding with another admin edit should fail loudly and be retried by a human, not silently win.
- **Stocktake:** a session per location. Counted quantities captured per `(sku, location, batch)`, variances reviewed, then posted as `stocktake` movements in one transaction. Nothing is written until posting, so a half-finished count cannot corrupt live stock.
- A stocktake in progress does not block trading. Variances are computed against the level **at posting time**, not at count time, with the delta shown for review.

---

## 8. Backorders and incoming stock

- `stock_levels.incoming_base_qty` is maintained from open purchase order lines and gives the "expected by" date on the product page.
- **Back-in-stock subscriptions** are notified after commit of any `goods_in` that takes `available` from zero to positive. The reference system shows "Out of stock" and captures nothing, which is pure lost demand.
- Backorder lines are allocated automatically on receipt, in order of `orders.placed_at` ascending — first ordered, first served, which is both defensible to customers and trivial to explain.

---

## 9. Reconciliation

Three scheduled jobs. Each writes a result record; each failure is an alert, not a log line.

| Job | Frequency | Assertion | On drift |
|---|---|---|---|
| Level vs ledger | Nightly | `stock_levels.on_hand_base_qty` = ledger sum, per key | **P1.** Report, do not auto-correct |
| Incoming vs open POs | Nightly | Σ `incoming_base_qty` per `(sku, location)`, summed across that SKU's rows (it lives on the NULL-batch row, 02 §23.5), = 05.7 §5.1's open-PO query | **P1** |
| Allocation vs levels | Hourly | `Σ stock_allocations(active).base_qty` = `allocated_base_qty` | **P1** |
| Serial count vs level | Nightly | §6.3 invariant | **P1** |
| Stale allocation reaper | Every 15 min | Release allocations on `pending_payment` orders older than the hold window (default 2 h) | Normal operation, logged |
| Batch expiry sweep | Nightly | Transition `active → expired` past `expires_on`; report expiring within 30 days | Normal operation |

**Drift is never auto-corrected.** An automatic fix hides the bug that caused it and destroys the evidence. The rebuild command exists and is run deliberately, by a human, after the cause is understood.

---

## 10. Growth and partitioning

`stock_movements` is the only unbounded table. Estimate at 200 orders/day × 6 lines × 3 movements ≈ 3,600/day ≈ 1.3M/year, plus receipts and adjustments.

- **Partitioning is applied from day one**, not deferred. PostgreSQL declarative partitioning is cheap enough at this volume that there is no reason to wait: `PARTITION BY RANGE (occurred_at)`, one partition per year plus a `DEFAULT` catch-all (Doc 02 §7.4).
- This changes the retention story from a future migration into a single statement: `ALTER TABLE stock_movements DETACH PARTITION stock_movements_2026` moves a year to cold storage with no delete storm and no bloat.
- Partition pruning applies to every period-scoped query, so the recall trace and ledger reports read only the partitions the batch was in circulation.
- `VACUUM`, `ANALYZE` and index maintenance are scoped per partition, which matters more here than the query gain: autovacuum on a single 20M-row append-only table is the thing that would eventually hurt.
- A yearly job creates the next partition ahead of time; the `DEFAULT` partition is monitored and should always be empty. A row landing in it is a P2 — it means the job failed.
- **A BRIN index on `occurred_at`** replaces what would have been a B-tree. The table is append-only so timestamps are strongly physically correlated, making BRIN roughly three orders of magnitude smaller for the same range queries.

---

## 11. Events emitted

All dispatched after commit.

| Event | Consumers |
|---|---|
| `StockReceived` | back-in-stock notifications, search reindex, PO progress |
| `StockAllocated` | order confirmation, warehouse queue |
| `StockDeallocated` | backorder reprocessing |
| `StockDispatched` | dispatch email, invoice, courier manifest, commission |
| `StockAdjusted` | audit, low-stock check |
| `LevelBelowReorderPoint` | purchasing dashboard |
| `BatchExpiringSoon` | operations dashboard |
| `BatchRecalled` | recall trace, customer notification |
| `ProjectionDriftDetected` | P1 alert |

---

## 12. Test matrix

**Concurrency tests** — the ones that justify this document:

| # | Scenario | Assertion |
|---|---|---|
| C1 | 50 parallel orders for 1 unit each against 10 on hand | Exactly 10 succeed, 40 get clean shortfalls, **0 oversells** |
| C2 | 20 parallel orders, same 3 SKUs, randomised line order | 0 deadlocks reported to callers; retries bounded at 3 |
| C3 | Process killed mid-transaction | No partial allocation; level matches ledger on restart |
| C4 | Concurrent allocation + admin adjustment on one key | Adjustment fails on version conflict; allocation unaffected |
| C5 | Same request replayed twice (retry/double-click) | `stock_allocations_identity_uq` prevents double reservation |
| C6 | Dispatch and cancel racing on one order | Exactly one wins; `allocated` does not go negative |
| C7 | Walk-in till sale racing a collection booking on the last unit | Exactly one succeeds; a collection reservation placed first is respected |

**Property tests:**

| Property | Assertion |
|---|---|
| Ledger sums to level | For any random movement sequence, rebuild equals projection |
| Non-negativity | `on_hand >= 0` and `allocated >= 0` after any valid sequence |
| Allocation conservation | `Σ active allocations = allocated_base_qty`, always |
| Serial count | §6.3 holds after any valid sequence |
| Append-only | No test can produce an UPDATE or DELETE on `stock_movements` (asserted via query log) |
| Batch split integrity | `Σ allocation.base_qty` per line = `order_lines.base_qty` |

**Flow fixtures:** goods-in with batch and expiry · FEFO selection across 3 batches · line split across 2 batches · serial receipt, allocation, pick, dispatch · quarantined batch excluded but stock still visible · `min_remaining_shelf_life` filter excluding a valid-but-near-expiry batch · partial dispatch then completion · return inspected to restock, and to quarantine · stocktake variance posted · backorder auto-allocated on receipt · recall trace from batch to customers.

---

## 13. Performance budgets

| Query | Budget | Index |
|---|---|---|
| Level lookup, 100 SKUs (order pad) | 10 ms | `stock_levels` PRIMARY |
| Allocation lock hold, 6-line order | 5 ms | `stock_levels` PRIMARY |
| FEFO batch selection per line | 10 ms | `batches_fefo_idx` |
| Serial selection per line | 10 ms | `stock_serials_pick_idx` |
| Ledger for one SKU, 12 months | 50 ms | `stock_movements_sku_loc_batch_idx` |
| Low stock / reorder report | 200 ms | `stock_levels_reorder_idx` |
| Recall trace | 500 ms | `stock_movements_batch_idx` |
| Nightly full reconciliation | 10 min | table scan, off-peak |

---

## 14. Open questions

| # | Question | Blocking? |
|---|---|---|
| 1 | Allocation hold window for `pending_payment` orders — 2 h assumed | No, configurable |
| 2 | Max batches per line — 5 assumed | No, configurable |
| 3 | ~~Collection: allocate at order time or at collection?~~ | **CLOSED — at placement**, protecting reserved stock from walk-in sales. §4.7 |
| 4 | ~~Bin-level allocation at launch?~~ | **CLOSED — location-level allocation, bins advisory** via `suggested_bin_id`. §4.7 |
| 5 | Retention before partition pruning | No, see §10 |

---

## 15. Acceptance criteria

1. C1–C6 pass, with C1 repeated 100 times and **zero** oversells.
2. All 6 property tests pass at 1,000 iterations.
3. All 11 flow fixtures pass.
4. Every budget in §13 met, verified by `EXPLAIN` assertion.
5. Level rebuild from ledger reproduces the projection exactly on a 100k-movement dataset.
6. A test proves no code path issues `UPDATE` or `DELETE` against `stock_movements`.
7. Lock ordering asserted by inspecting generated SQL, not by inspection of the source.
8. Recall trace returns correct customers for a batch dispatched across 20 orders.
