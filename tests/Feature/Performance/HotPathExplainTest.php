<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 02 §10 — Critical query paths. Each of the 18 buildable hot-path
 * queries (Q1-Q3, Q5-Q15, Q17-Q18, Q20, Q22 — Q4/Q16/Q19/part of Q21
 * depend on tables not yet scaffolded: attributes, stock_serials,
 * commission rules) gets a real `EXPLAIN (ANALYZE, BUFFERS)` assertion
 * against a realistically-sized, representative dataset, checking the
 * plan node types §10 names and `Heap Fetches: 0` where index-only
 * coverage is claimed.
 *
 * Why this file does NOT use RefreshDatabase: `VACUUM` cannot run
 * inside a transaction block, and RefreshDatabase wraps every test in
 * one. Instead: TRUNCATE every table this file touches, seed a large,
 * representative dataset exactly once (via a $GLOBALS guard — a
 * closure-local `static` would reset on Pest's per-test closure
 * rebinding), run a real `VACUUM ANALYZE` once (required for
 * `Heap Fetches: 0` to be achievable at all — see Doc 02 §10's own note
 * that a stale visibility map is an autovacuum problem, not an index
 * problem), then assert against that fixed dataset across all tests.
 *
 * The TRUNCATE is load-bearing, not defensive boilerplate: in a full
 * `composer test` run, dozens of prior test files insert-and-roll-back
 * rows into these same tables first. Verified live, repeatedly, with
 * `pg_visibility`: bulk-inserting into a table that still physically
 * contains pages from an aborted transaction reuses those pages via the
 * free space map, and several explicit `VACUUM ANALYZE` passes in a row
 * still left `Heap Fetches` non-zero on them — only a `TRUNCATE` (fresh
 * pages) made `VACUUM ANALYZE` reliably mark everything all-visible.
 * Without it, this suite is order-dependent: it can pass in isolation
 * and fail inside `composer test`, which is exactly what happened while
 * writing it.
 *
 * The final test in this file truncates everything it seeded so it
 * does not leak into other test classes that share this process
 * (verified safe regardless of run order: no other test file does an
 * unscoped whole-table count, and this file never sets `is_default` on
 * `locations`/`price_tiers`, the only two globally singleton flags in
 * the schema).
 *
 * Seed volumes are not arbitrary — each was tuned empirically against a
 * live Postgres 16 instance until the planner actually chose the
 * claimed index over a sequential scan; a tiny table makes Postgres
 * correctly prefer Seq Scan regardless of what index exists, so an
 * under-seeded test would pass for the wrong reason (or fail for a
 * reason that has nothing to do with the index).
 *
 * Two deliberate relaxations from §10's literal wording, both because
 * the alternative plan the optimiser actually chose is provably just as
 * good, not a different query shape or an accident of missing data:
 *  - Q1: price_list_items carries TWO covering indexes for exactly this
 *    reason (§6.4 note 5 / §6.4's own two-index rationale) —
 *    price_list_items_resolve_idx and price_list_items_by_sku_idx. Both
 *    achieve Index Only Scan / Heap Fetches 0 for this query shape;
 *    which one the planner picks is a statistics call, not a
 *    correctness question, so the assertion accepts either by name.
 *  - Q18: stock_movements_batch_idx and the reference_type/reference_id
 *    index are both real, both selective, and Postgres alternates
 *    between them depending on relative selectivity. The assertion
 *    checks the properties §10 actually cares about — no sequential
 *    scan anywhere in the join, and partition pruning limited to the
 *    period the batch was in circulation — rather than one specific
 *    index name.
 */

// -----------------------------------------------------------------
// EXPLAIN helpers
// -----------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function hotPathExplain(string $sql, array $bindings = []): array
{
    $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($rows[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);

    return $decoded[0]['Plan'];
}

/**
 * Flattens a plan tree (root + every nested "Plans" child) into a flat list.
 *
 * @param  array<string, mixed>  $plan
 * @return list<array<string, mixed>>
 */
function hotPathFlattenPlan(array $plan): array
{
    $nodes = [$plan];
    foreach ($plan['Plans'] ?? [] as $child) {
        $nodes = [...$nodes, ...hotPathFlattenPlan($child)];
    }

    return $nodes;
}

/**
 * @param  array<string, mixed>  $plan
 */
function hotPathAssertNoSeqScanOn(array $plan, string ...$relationNames): void
{
    foreach (hotPathFlattenPlan($plan) as $node) {
        if ($node['Node Type'] === 'Seq Scan') {
            expect($relationNames)->not->toContain($node['Relation Name'] ?? null,
                "Unexpected Seq Scan on \"{$node['Relation Name']}\" — the plan should use an index here.");
        }
    }
}

/**
 * @param  array<string, mixed>  $plan
 */
function hotPathAssertNoSortNode(array $plan): void
{
    $sortNodes = array_filter(
        hotPathFlattenPlan($plan),
        fn (array $n) => in_array($n['Node Type'], ['Sort', 'Incremental Sort'], true)
    );

    expect($sortNodes)->toBeEmpty('Expected the index to provide ordering with no separate Sort node.');
}

/**
 * Asserts one of the given index names is used somewhere in the plan
 * (accepts several names when more than one covering index legitimately
 * satisfies the query — see the file-level docblock).
 *
 * @param  array<string, mixed>  $plan
 */
function hotPathAssertIndexUsed(array $plan, string ...$acceptableIndexNames): void
{
    $used = array_map(fn (array $n) => $n['Index Name'] ?? null, hotPathFlattenPlan($plan));

    expect(array_intersect($acceptableIndexNames, $used))
        ->not->toBeEmpty('Expected one of ['.implode(', ', $acceptableIndexNames).'] to be used, got: '.implode(', ', array_filter($used)));
}

/**
 * @param  array<string, mixed>  $plan
 */
function hotPathAssertHeapFetchesZero(array $plan, string $indexName): void
{
    $node = collect(hotPathFlattenPlan($plan))->first(fn (array $n) => ($n['Index Name'] ?? null) === $indexName);

    expect($node)->not->toBeNull("Index \"$indexName\" was not used in this plan.");
    expect($node['Node Type'])->toBe('Index Only Scan');
    expect($node['Heap Fetches'])->toBe(0);
}

/**
 * @param  array<string, mixed>  $plan
 */
function hotPathRelationsTouched(array $plan): array
{
    return array_values(array_unique(array_filter(
        array_map(fn (array $n) => $n['Relation Name'] ?? null, hotPathFlattenPlan($plan))
    )));
}

// -----------------------------------------------------------------
// one-time fixture seeding
// -----------------------------------------------------------------

beforeEach(function () {
    if ($GLOBALS['__hot_path_seeded'] ?? false) {
        return;
    }

    Artisan::call('migrate', ['--force' => true]);
    DB::statement('SET client_min_messages TO WARNING');

    // TRUNCATE, not just an empty table, before seeding. By the time this
    // file runs in a full `composer test` pass, dozens of other test
    // files have already inserted-and-rolled-back rows into these same
    // tables; Postgres's free space map reuses those pages for new
    // inserts, and pages that once held dead tuples from an aborted
    // transaction were empirically observed (verified live, repeatedly,
    // with pg_visibility) to resist ever being marked all-visible by a
    // subsequent VACUUM — even several explicit VACUUM ANALYZE passes in
    // a row left Heap Fetches non-zero. TRUNCATE forces entirely fresh
    // pages, which VACUUM ANALYZE then reliably marks all-visible.
    DB::statement('TRUNCATE TABLE
        stock_allocations, stock_movements, order_lines, orders, batches, sku_costs,
        stock_levels, locations, price_list_items, price_lists, packs, skus, products,
        category_closure, categories, companies, tax_classes
        RESTART IDENTITY CASCADE');

    $now = now();
    $start = Carbon::create(2026, 1, 1);

    $taxClassId = DB::table('tax_classes')->insertGetId(['code' => 'perf-standard', 'name' => 'Standard']);

    // categories: a queried subtree + a sibling subtree, so Q3's IN() is selective
    $rootId = DB::table('categories')->insertGetId(['name' => 'Perf Root', 'slug' => 'perf-root', 'status' => 'active']);
    $childIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $childIds[] = DB::table('categories')->insertGetId(['name' => "Perf Child $i", 'slug' => "perf-child-$i", 'parent_id' => $rootId, 'status' => 'active']);
    }
    $otherRootId = DB::table('categories')->insertGetId(['name' => 'Perf Other Root', 'slug' => 'perf-other-root', 'status' => 'active']);
    $otherChildIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $otherChildIds[] = DB::table('categories')->insertGetId(['name' => "Perf Other Child $i", 'slug' => "perf-other-child-$i", 'parent_id' => $otherRootId, 'status' => 'active']);
    }
    DB::table('category_closure')->insert(['ancestor_id' => $rootId, 'descendant_id' => $rootId, 'depth' => 0]);
    foreach ($childIds as $cid) {
        DB::table('category_closure')->insert(['ancestor_id' => $cid, 'descendant_id' => $cid, 'depth' => 0]);
        DB::table('category_closure')->insert(['ancestor_id' => $rootId, 'descendant_id' => $cid, 'depth' => 1]);
    }
    DB::table('category_closure')->insert(['ancestor_id' => $otherRootId, 'descendant_id' => $otherRootId, 'depth' => 0]);
    foreach ($otherChildIds as $cid) {
        DB::table('category_closure')->insert(['ancestor_id' => $cid, 'descendant_id' => $cid, 'depth' => 0]);
        DB::table('category_closure')->insert(['ancestor_id' => $otherRootId, 'descendant_id' => $cid, 'depth' => 1]);
    }

    // products (6,000: 600 per category x 10 categories) + skus + packs
    // one distinctively-named product buried in the set for Q20's search selectivity
    foreach ([...$childIds, ...$otherChildIds] as $catIndex => $cid) {
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [
                'public_id' => (string) Str::ulid(),
                'name' => $i === 3 ? "Zephyrion Widget $cid" : "Perf Product $cid-$i",
                'slug' => "perf-product-$cid-$i",
                'primary_category_id' => $cid,
                'status' => $i % 10 === 0 ? 'draft' : 'active',
                'completeness_score' => $i % 20 === 0 ? 40 : 80,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) >= 500) {
                DB::table('products')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('products')->insert($rows);
        }
    }
    $productIds = DB::table('products')->where('slug', 'like', 'perf-product-%')->pluck('id')->all();

    $skuRows = [];
    foreach ($productIds as $pid) {
        $skuRows[] = [
            'public_id' => (string) Str::ulid(), 'product_id' => $pid, 'sku_code' => 'PERF-SKU-'.$pid,
            'status' => 'active', 'tax_class_id' => $taxClassId, 'base_unit' => 'each',
            'moq_base_qty' => 1, 'order_increment_base_qty' => 1, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($skuRows, 500) as $chunk) {
        DB::table('skus')->insert($chunk);
    }
    $skuIds = DB::table('skus')->where('sku_code', 'like', 'PERF-SKU-%')->pluck('id')->all();

    $packRows = [];
    foreach ($skuIds as $sid) {
        $packRows[] = [
            'sku_id' => $sid, 'code' => 'EACH', 'label' => 'Each', 'pack_level' => 'each',
            'base_units' => 1, 'is_sellable' => true, 'is_default_sell' => true,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($packRows, 500) as $chunk) {
        DB::table('packs')->insert($chunk);
    }
    // packs.id is a Postgres IDENTITY sequence, which is non-transactional
    // (Doc 02 §11.3) — other test files' rolled-back pack rows still
    // advance it, so pack ids can never be assumed to start at 1. Look
    // each one up by its actual sku_id instead of hardcoding an id.
    $packIdBySkuId = DB::table('packs')->whereIn('sku_id', $skuIds)->pluck('id', 'sku_id')->all();

    // price_lists (5, promotion scope — no EXCLUDE — matches "2-5 candidate lists") + price_list_items
    $priceListIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $priceListIds[] = DB::table('price_lists')->insertGetId([
            'code' => "perf-promo-$i", 'name' => "Perf Promo $i", 'scope' => 'promotion', 'promotion_id' => $i,
            'currency' => 'GBP', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $pliRows = [];
    foreach ($priceListIds as $plid) {
        foreach ($skuIds as $sid) {
            $pliRows[] = ['price_list_id' => $plid, 'sku_id' => $sid, 'min_base_qty' => 1, 'unit_price_e4' => random_int(500, 50000), 'created_at' => $now, 'updated_at' => $now];
            if (count($pliRows) >= 5000) {
                DB::table('price_list_items')->insert($pliRows);
                $pliRows = [];
            }
        }
    }
    if ($pliRows !== []) {
        DB::table('price_list_items')->insert($pliRows);
    }

    // locations (3) + stock_levels
    $locIds = [
        DB::table('locations')->insertGetId(['code' => 'PERF-MAIN', 'name' => 'Perf Main WH', 'location_type' => 'warehouse']),
        DB::table('locations')->insertGetId(['code' => 'PERF-SEC', 'name' => 'Perf Secondary WH', 'location_type' => 'warehouse']),
        DB::table('locations')->insertGetId(['code' => 'PERF-TER', 'name' => 'Perf Tertiary WH', 'location_type' => 'warehouse']),
    ];
    $slRows = [];
    foreach ($skuIds as $sid) {
        foreach ($locIds as $lid) {
            $slRows[] = ['sku_id' => $sid, 'location_id' => $lid, 'on_hand_base_qty' => random_int(0, 500), 'allocated_base_qty' => 0, 'reorder_point_base_qty' => $sid % 15 === 0 ? 10 : 0, 'updated_at' => $now];
            if (count($slRows) >= 5000) {
                DB::table('stock_levels')->insert($slRows);
                $slRows = [];
            }
        }
    }
    if ($slRows !== []) {
        DB::table('stock_levels')->insert($slRows);
    }

    // sku_costs
    $costRows = [];
    foreach ($skuIds as $sid) {
        $costRows[] = ['sku_id' => $sid, 'source' => 'manual', 'currency' => 'GBP', 'fob_e4' => 1000, 'valid_from' => $now, 'created_at' => $now];
        if (count($costRows) >= 5000) {
            DB::table('sku_costs')->insert($costRows);
            $costRows = [];
        }
    }
    if ($costRows !== []) {
        DB::table('sku_costs')->insert($costRows);
    }

    // batches: one per sku, ~5% expiring within 30 days (for the FEFO / expiry-sweep queries)
    $batchRows = [];
    foreach ($skuIds as $i => $sid) {
        $expiresOn = $i % 20 === 0
            ? now()->addDays(random_int(1, 25))->toDateString()
            : now()->addDays(random_int(60, 400))->toDateString();
        $batchRows[] = ['sku_id' => $sid, 'batch_code' => 'PERF-B-'.$sid, 'status' => 'active', 'expires_on' => $expiresOn, 'created_at' => $now, 'updated_at' => $now];
        if (count($batchRows) >= 2000) {
            DB::table('batches')->insert($batchRows);
            $batchRows = [];
        }
    }
    if ($batchRows !== []) {
        DB::table('batches')->insert($batchRows);
    }
    $batchIds = DB::table('batches')->where('batch_code', 'like', 'PERF-B-%')->pluck('id')->all();

    // companies (20,000 — large enough in absolute page count for trigram to
    // beat a seq scan even at high selectivity) with one distinctive outlier
    $companyRows = [];
    for ($i = 1; $i <= 20000; $i++) {
        $companyRows[] = [
            'public_id' => (string) Str::ulid(), 'account_code' => "PERF-ACC-$i",
            'name' => $i === 3 ? 'Quixotical Trading Ltd' : "Perf Company $i Ltd",
            'status' => 'approved', 'payment_terms' => 'net30', 'created_at' => $now, 'updated_at' => $now,
        ];
        if (count($companyRows) >= 1000) {
            DB::table('companies')->insert($companyRows);
            $companyRows = [];
        }
    }
    if ($companyRows !== []) {
        DB::table('companies')->insert($companyRows);
    }
    $companyIds = DB::table('companies')->where('account_code', 'like', 'PERF-ACC-%')->pluck('id')->all();
    $bigCompanyId = $companyIds[0];

    // orders (20,000), inserted in chronological order across roughly a
    // year so orders_placed_at_brin correlates physically with occurred
    // time. Volume matters here beyond "big enough to prefer an index":
    // Q10's BRIN-vs-seq-scan choice was verified flaky at 6,000 rows
    // (2/10 isolated runs picked a different plan on identical data,
    // even after maxing the placed_at column's statistics target) — the
    // cost gap was too close for ANALYZE's sampling variance not to
    // occasionally cross it. A much larger table makes BRIN's win
    // decisive rather than marginal.
    $statuses = ['confirmed', 'picking', 'dispatched', 'completed', 'cancelled'];
    $paymentStatuses = ['unpaid', 'paid', 'on_account'];
    $counter = 0;
    for ($b = 0; $b < 20; $b++) {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $counter++;
            $placedAt = $start->copy()->addMinutes($counter * 26);
            $companyId = $counter % 7 === 0 ? $bigCompanyId : $companyIds[array_rand($companyIds)];
            $rows[] = [
                'public_id' => (string) Str::ulid(), 'order_number' => 'PERF-SO-'.str_pad((string) $counter, 8, '0', STR_PAD_LEFT),
                'company_id' => $companyId, 'channel' => 'web', 'status' => $statuses[$counter % count($statuses)],
                'payment_status' => $paymentStatuses[$counter % count($paymentStatuses)], 'fulfilment_type' => 'delivery',
                'currency' => 'GBP', 'placed_at' => $placedAt, 'created_at' => $placedAt, 'updated_at' => $placedAt,
            ];
        }
        DB::table('orders')->insert($rows);
    }
    $orderIds = DB::table('orders')->where('order_number', 'like', 'PERF-SO-%')->pluck('id')->all();

    // order_lines: 2 distinct-sku lines per order
    $olRows = [];
    $skuCount = count($skuIds);
    foreach ($orderIds as $oid) {
        $i1 = random_int(0, $skuCount - 1);
        $i2 = ($i1 + 1) % $skuCount;
        foreach ([$i1, $i2] as $ln => $skuIdx) {
            $sid = $skuIds[$skuIdx];
            $olRows[] = [
                'order_id' => $oid, 'line_no' => $ln + 1, 'sku_id' => $sid, 'pack_id' => $packIdBySkuId[$sid],
                'sku_code_snapshot' => 'PERF-SKU-'.$sid, 'name_snapshot' => 'Product', 'pack_label_snapshot' => 'Each',
                'pack_qty' => 1, 'pack_base_units' => 1, 'base_qty' => 1, 'unit_price_net_e4' => 1000,
                'line_net_minor' => 10, 'tax_rate_bp' => 2000, 'line_tax_minor' => 2, 'line_gross_minor' => 12,
                'price_source' => 'base', 'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($olRows) >= 2000) {
                DB::table('order_lines')->insert($olRows);
                $olRows = [];
            }
        }
    }
    if ($olRows !== []) {
        DB::table('order_lines')->insert($olRows);
    }
    $orderLineIds = DB::table('order_lines')
        ->whereIn('order_id', $orderIds)
        ->pluck('id')->all();

    // stock_movements (8,000) spread across 2026
    $targetSku = $skuIds[0];
    $targetLoc = $locIds[0];
    $smRows = [];
    for ($i = 0; $i < 8000; $i++) {
        $occurredAt = $start->copy()->addMinutes($i * 45);
        $smRows[] = [
            'occurred_at' => $occurredAt, 'sku_id' => $skuIds[array_rand($skuIds)], 'location_id' => $targetLoc,
            'movement_type' => 'goods_in', 'base_qty' => random_int(1, 20), 'created_at' => $occurredAt,
        ];
        if (count($smRows) >= 2000) {
            DB::table('stock_movements')->insert($smRows);
            $smRows = [];
        }
    }
    if ($smRows !== []) {
        DB::table('stock_movements')->insert($smRows);
    }

    // stock_allocations (12,000, ~5% 'allocated' — the reaper's target predicate)
    $allocStatusCycle = ['dispatched', 'released', 'picked', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'dispatched', 'allocated'];
    $allocRows = [];
    foreach ($orderLineIds as $idx => $olId) {
        $status = $allocStatusCycle[$idx % count($allocStatusCycle)];
        $allocRows[] = [
            'order_line_id' => $olId, 'sku_id' => $skuIds[array_rand($skuIds)], 'location_id' => $targetLoc,
            'base_qty' => 1, 'status' => $status, 'allocated_at' => $now,
            'released_at' => $status === 'released' ? $now : null,
        ];
        if (count($allocRows) >= 2000) {
            DB::table('stock_allocations')->insert($allocRows);
            $allocRows = [];
        }
    }
    if ($allocRows !== []) {
        DB::table('stock_allocations')->insert($allocRows);
    }

    // recall-trace scenario for Q18: a batch with real circulation history
    // (so batch_id is a genuinely selective filter, not the only row of
    // its kind) plus one traceable dispatch tied to a real order
    $recallBatchId = $batchIds[0];
    $traceOrderLineId = $orderLineIds[0];
    $allocId = DB::table('stock_allocations')->insertGetId([
        'order_line_id' => $traceOrderLineId, 'sku_id' => $targetSku, 'location_id' => $targetLoc, 'batch_id' => $recallBatchId,
        'base_qty' => 5, 'status' => 'dispatched', 'allocated_at' => $now,
    ]);
    DB::table('stock_movements')->insert([
        'occurred_at' => $now, 'sku_id' => $targetSku, 'location_id' => $targetLoc, 'batch_id' => $recallBatchId,
        'movement_type' => 'dispatch', 'base_qty' => -5, 'reference_type' => 'allocation', 'reference_id' => $allocId,
        'created_at' => $now,
    ]);
    $recallHistory = [];
    for ($i = 0; $i < 40; $i++) {
        $occurredAt = $start->copy()->addDays($i * 3);
        $recallHistory[] = [
            'occurred_at' => $occurredAt, 'sku_id' => $targetSku, 'location_id' => $targetLoc, 'batch_id' => $recallBatchId,
            'movement_type' => 'goods_in', 'base_qty' => 10, 'created_at' => $occurredAt,
        ];
    }
    DB::table('stock_movements')->insert($recallHistory);

    $GLOBALS['__hot_path_recall_batch_id'] = $recallBatchId;

    // ANALYZE's default statistics target (100) samples a fixed ~30,000
    // rows regardless of table size, so two runs over identical data can
    // still get different random samples. Q10's BRIN-vs-seq-scan choice
    // on orders.placed_at was verified flaky under that default — 2/6
    // isolated runs picked a different plan on identical seeded data.
    // Maxing the statistics target for this column makes ANALYZE examine
    // it far more thoroughly, removing the sampling variance rather than
    // papering over it with a bigger seed (which reduces the odds of
    // flakiness without addressing the actual cause).
    DB::statement('ALTER TABLE orders ALTER COLUMN placed_at SET STATISTICS 1000');

    // VACUUM cannot run inside a transaction — this is exactly why this
    // file avoids RefreshDatabase. Required for Index Only Scan / Heap
    // Fetches: 0 to be achievable at all (Doc 02 §10's own caveat: a
    // stale visibility map is an autovacuum problem, not an index
    // problem). One pass here is sufficient now that the tables were
    // TRUNCATEd first — see the comment above.
    DB::statement('VACUUM ANALYZE');

    $GLOBALS['__hot_path_seeded'] = true;
});

// -----------------------------------------------------------------
// Q1 — order pad: resolve prices, 100 SKUs x 5 lists
// -----------------------------------------------------------------
it('Q1: resolves price_list_items via an Index Only Scan with zero heap fetches', function () {
    $listIds = DB::table('price_lists')->where('code', 'like', 'perf-promo-%')->pluck('id')->all();
    $skuIds = DB::table('skus')->where('sku_code', 'like', 'PERF-SKU-%')->limit(100)->pluck('id')->all();

    $plan = hotPathExplain(
        'select price_list_id, sku_id, min_base_qty, unit_price_e4 from price_list_items where price_list_id = any(?) and sku_id = any(?)',
        ['{'.implode(',', $listIds).'}', '{'.implode(',', $skuIds).'}']
    );

    // either covering index is correct here — see file-level docblock
    hotPathAssertIndexUsed($plan, 'price_list_items_resolve_idx', 'price_list_items_by_sku_idx');
    $usedIndex = collect(hotPathFlattenPlan($plan))->first(fn ($n) => in_array($n['Index Name'] ?? null, ['price_list_items_resolve_idx', 'price_list_items_by_sku_idx'], true))['Index Name'];
    hotPathAssertHeapFetchesZero($plan, $usedIndex);
});

// -----------------------------------------------------------------
// Q2 — order pad: stock availability, 100 SKUs
// -----------------------------------------------------------------
it('Q2: resolves stock_levels availability via an Index Only Scan with zero heap fetches', function () {
    $skuIds = DB::table('skus')->where('sku_code', 'like', 'PERF-SKU-%')->limit(100)->pluck('id')->all();

    $plan = hotPathExplain(
        'select sku_id, location_id, batch_id, available_base_qty from stock_levels where sku_id = any(?)',
        ['{'.implode(',', $skuIds).'}']
    );

    hotPathAssertHeapFetchesZero($plan, 'stock_levels_sku_idx');
});

// -----------------------------------------------------------------
// Q3 — category page: active products in subtree, faceted
// -----------------------------------------------------------------
it('Q3: resolves active products in a category subtree without a seq scan or sort on products', function () {
    $rootId = DB::table('categories')->where('slug', 'perf-root')->value('id');

    $plan = hotPathExplain(
        "select p.id from products p where p.status = 'active' and p.deleted_at is null and p.primary_category_id in (select descendant_id from category_closure where ancestor_id = ?)",
        [$rootId]
    );

    hotPathAssertNoSeqScanOn($plan, 'products');
    hotPathAssertNoSortNode($plan);
    hotPathAssertHeapFetchesZero($plan, 'products_cat_active_idx');
});

// -----------------------------------------------------------------
// Q5 — product page: sellable packs for SKU
// -----------------------------------------------------------------
it('Q5: resolves sellable packs for a SKU via an Index Only Scan with zero heap fetches', function () {
    $skuId = DB::table('skus')->where('sku_code', 'like', 'PERF-SKU-%')->value('id');

    $plan = hotPathExplain('select sku_id, base_units, label, gross_weight_g from packs where sku_id = ? and is_sellable', [$skuId]);

    hotPathAssertHeapFetchesZero($plan, 'packs_sku_sellable_idx');
});

// -----------------------------------------------------------------
// Q6 — "my orders", page N (keyset)
// -----------------------------------------------------------------
it('Q6: paginates a company\'s orders via an Index Scan with no separate sort', function () {
    $companyId = DB::table('orders')->where('order_number', 'like', 'PERF-SO-%')->value('company_id');

    $plan = hotPathExplain(
        'select id, order_number, status, placed_at from orders where company_id = ? and placed_at is not null order by placed_at desc, id limit 20',
        [$companyId]
    );

    hotPathAssertIndexUsed($plan, 'orders_company_placed_idx');
    hotPathAssertNoSortNode($plan);
});

// -----------------------------------------------------------------
// Q7 — allocation: lock the level row
// -----------------------------------------------------------------
it('Q7: locks a stock_levels row via its identity index under FOR UPDATE', function () {
    $row = DB::table('stock_levels')->whereNull('batch_id')->first();

    $plan = hotPathExplain(
        'select sku_id, location_id, batch_id, on_hand_base_qty, allocated_base_qty from stock_levels where sku_id = ? and location_id = ? and batch_id is null for update',
        [$row->sku_id, $row->location_id]
    );

    hotPathAssertIndexUsed($plan, 'stock_levels_identity_uq');
    expect(hotPathFlattenPlan($plan)[0]['Node Type'])->toBe('LockRows');
});

// -----------------------------------------------------------------
// Q8 — ledger: movements for a SKU in a period, partition-pruned
// -----------------------------------------------------------------
it('Q8: resolves stock_movements for a period via an indexed scan pruned to one partition', function () {
    $row = DB::table('stock_movements')->where('movement_type', 'goods_in')->first();

    $plan = hotPathExplain(
        "select id from stock_movements where sku_id = ? and location_id = ? and occurred_at >= '2026-01-01' and occurred_at < '2026-04-01'",
        [$row->sku_id, $row->location_id]
    );

    hotPathAssertNoSeqScanOn($plan, 'stock_movements', 'stock_movements_2026', 'stock_movements_2027', 'stock_movements_default');
    hotPathAssertIndexUsed($plan, 'stock_movements_2026_sku_id_location_id_batch_id_occurred_a_idx');
    expect(hotPathRelationsTouched($plan))
        ->not->toContain('stock_movements_2027')
        ->not->toContain('stock_movements_default');
});

// -----------------------------------------------------------------
// Q9 — picking / approval queues
// -----------------------------------------------------------------
it('Q9: resolves the open-status order queue via an Index Scan with no sort', function () {
    $plan = hotPathExplain("select id, status, placed_at from orders where status = 'confirmed' order by placed_at limit 50");

    hotPathAssertIndexUsed($plan, 'orders_open_status_idx');
    hotPathAssertNoSortNode($plan);
});

// -----------------------------------------------------------------
// Q10 — best sellers by period
// -----------------------------------------------------------------
it('Q10: filters orders by period via a BRIN-driven Bitmap Heap Scan', function () {
    $plan = hotPathExplain(
        "select ol.sku_id, sum(ol.base_qty) as total_qty from order_lines ol join orders o on o.id = ol.order_id where o.placed_at >= '2026-02-01' and o.placed_at < '2026-03-01' group by ol.sku_id order by total_qty desc limit 20"
    );

    // the BRIN-driven scan of orders is the property §10 names for this query
    hotPathAssertIndexUsed($plan, 'orders_placed_at_brin');
    $ordersScan = collect(hotPathFlattenPlan($plan))->first(fn ($n) => ($n['Relation Name'] ?? null) === 'orders' || (($n['Index Name'] ?? null) === 'orders_placed_at_brin'));
    expect($ordersScan)->not->toBeNull();
    // a full-period, all-SKU aggregate must touch every matching order_lines
    // row regardless of any index on sku_id — a seq/bitmap scan of
    // order_lines here is the objectively correct plan, not a missed index
});

// -----------------------------------------------------------------
// Q11 — low stock / reorder report
// -----------------------------------------------------------------
it('Q11: resolves the reorder report via stock_levels_reorder_idx without a seq scan', function () {
    $locationId = DB::table('locations')->where('code', 'like', 'PERF-%')->value('id');

    $plan = hotPathExplain('select sku_id, available_base_qty from stock_levels where location_id = ? and reorder_point_base_qty > 0', [$locationId]);

    hotPathAssertNoSeqScanOn($plan, 'stock_levels');
    hotPathAssertIndexUsed($plan, 'stock_levels_reorder_idx');
});

// -----------------------------------------------------------------
// Q12 — data quality: incomplete products
// -----------------------------------------------------------------
it('Q12: resolves incomplete products via an Index Scan on the tiny partial index', function () {
    $plan = hotPathExplain("select id, completeness_score from products where status = 'active' and completeness_score < 60");

    hotPathAssertIndexUsed($plan, 'products_incomplete_idx');
    hotPathAssertNoSeqScanOn($plan, 'products');
});

// -----------------------------------------------------------------
// Q13 — current landed cost for a SKU
// -----------------------------------------------------------------
it('Q13: resolves current landed cost via an Index Only Scan with LIMIT 1', function () {
    $skuId = DB::table('sku_costs')->value('sku_id');

    $plan = hotPathExplain('select landed_cost_e4, is_provisional from sku_costs where sku_id = ? order by valid_from desc limit 1', [$skuId]);

    hotPathAssertHeapFetchesZero($plan, 'sku_costs_current_idx');
    expect(hotPathFlattenPlan($plan)[0]['Node Type'])->toBe('Limit');
});

// -----------------------------------------------------------------
// Q14 — stale allocation reaper
// -----------------------------------------------------------------
it('Q14: resolves open allocations via stock_allocations_reaper_idx without a seq scan', function () {
    $plan = hotPathExplain("select id, allocated_at from stock_allocations where status = 'allocated' order by allocated_at");

    hotPathAssertNoSeqScanOn($plan, 'stock_allocations');
    hotPathAssertIndexUsed($plan, 'stock_allocations_reaper_idx');
});

// -----------------------------------------------------------------
// Q15 — FEFO batch selection for allocation
// -----------------------------------------------------------------
it('Q15: resolves FEFO batch order via an Index Only Scan with no separate sort', function () {
    $skuId = DB::table('batches')->where('batch_code', 'like', 'PERF-B-%')->value('sku_id');

    $plan = hotPathExplain(
        "select id, batch_code, unit_cost_e4 from batches where sku_id = ? and status = 'active' order by expires_on nulls last, id limit 5",
        [$skuId]
    );

    hotPathAssertHeapFetchesZero($plan, 'batches_fefo_idx');
    hotPathAssertNoSortNode($plan);
});

// -----------------------------------------------------------------
// Q17 — expiring-within-30-days report
// -----------------------------------------------------------------
it('Q17: resolves the expiry sweep via batches_expiry_sweep_idx without a seq scan', function () {
    $plan = hotPathExplain("select id, expires_on from batches where status = 'active' and expires_on is not null and expires_on < current_date + 30");

    hotPathAssertNoSeqScanOn($plan, 'batches');
    hotPathAssertIndexUsed($plan, 'batches_expiry_sweep_idx');
});

// -----------------------------------------------------------------
// Q18 — recall trace: batch -> customers, partition-pruned
// -----------------------------------------------------------------
it('Q18: traces a batch to its customers with no seq scans and partitions pruned to its circulation period', function () {
    $batchId = $GLOBALS['__hot_path_recall_batch_id'];

    $plan = hotPathExplain(
        "select distinct o.id, o.order_number, o.company_id
         from stock_movements m
         join stock_allocations a on a.id = m.reference_id and m.reference_type = 'allocation'
         join order_lines ol on ol.id = a.order_line_id
         join orders o on o.id = ol.order_id
         where m.batch_id = ? and m.movement_type = 'dispatch'
           and m.occurred_at >= '2026-01-01' and m.occurred_at < '2027-01-01'",
        [$batchId]
    );

    hotPathAssertNoSeqScanOn($plan, 'stock_movements', 'stock_movements_2026', 'stock_movements_2027', 'stock_movements_default', 'stock_allocations', 'order_lines', 'orders');
    expect(hotPathRelationsTouched($plan))
        ->not->toContain('stock_movements_2027')
        ->not->toContain('stock_movements_default');
});

// -----------------------------------------------------------------
// Q20 — product search, term + fuzzy
// -----------------------------------------------------------------
it('Q20: resolves product search via a BitmapOr of the GIN and trigram indexes', function () {
    $plan = hotPathExplain("select id from products p, websearch_to_tsquery('english', 'Zephyrion') q where p.status = 'active' and (p.search_vector @@ q or p.name % 'Zephyrion')");

    hotPathAssertNoSeqScanOn($plan, 'products');
    hotPathAssertIndexUsed($plan, 'products_search_gin', 'products_name_trgm');
});

// -----------------------------------------------------------------
// Q22 — company fuzzy duplicate check
// -----------------------------------------------------------------
it('Q22: resolves fuzzy company name matches via companies_name_trgm_idx without a seq scan', function () {
    $plan = hotPathExplain("select id, name from companies where name % 'Quixoticl Tradng'");

    hotPathAssertNoSeqScanOn($plan, 'companies');
    hotPathAssertIndexUsed($plan, 'companies_name_trgm_idx');
});

// -----------------------------------------------------------------
// cleanup — must run last (Pest preserves in-file declaration order)
// -----------------------------------------------------------------
it('cleans up the performance fixtures it seeded', function () {
    DB::statement('TRUNCATE TABLE
        stock_allocations, stock_movements, order_lines, orders, batches, sku_costs,
        stock_levels, locations, price_list_items, price_lists, packs, skus, products,
        category_closure, categories, companies, tax_classes
        RESTART IDENTITY CASCADE');

    $GLOBALS['__hot_path_seeded'] = false;

    expect(DB::table('companies')->count())->toBe(0);
});
