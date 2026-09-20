<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.4 — stock_allocations. Allocation reserves, dispatch consumes
 * (§7.2 principle 3): placing an order increases allocated_base_qty, it
 * does not reduce on_hand_base_qty. `stock_allocations_identity_uq`
 * (UNIQUE NULLS NOT DISTINCT) is the defence against double-allocating
 * the same line from the same location+batch on a retried request.
 *
 * `batch_id` is intentionally left as a deferred nullable column with no
 * FK — `batches` (§7.5) is scaffolded in the next migration set.
 *
 * `suggested_bin_id` is also deferred: `bins` (§7.3) has full DDL in
 * Doc 02 but has not been scaffolded in any session's scope. It is
 * advisory only per the doc ("never locked") — a picker taking goods
 * from a different bin is not an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_allocations (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              order_line_id    bigint      NOT NULL REFERENCES order_lines (id),
              sku_id           bigint      NOT NULL REFERENCES skus (id),
              location_id      bigint      NOT NULL REFERENCES locations (id),
              batch_id         bigint,
              suggested_bin_id bigint,
              base_qty         integer     NOT NULL,
              status           text        NOT NULL DEFAULT 'allocated',
              allocated_at     timestamptz NOT NULL DEFAULT now(),
              released_at      timestamptz,

              CONSTRAINT stock_allocations_identity_uq
                UNIQUE NULLS NOT DISTINCT (order_line_id, location_id, batch_id),
              CONSTRAINT stock_allocations_status_chk
                CHECK (status IN ('allocated','picked','dispatched','released')),
              CONSTRAINT stock_allocations_qty_chk CHECK (base_qty > 0)
            );

            COMMENT ON COLUMN stock_allocations.batch_id IS
              'FK to batches(id) pending — batches (Doc 02 §7.5) is scaffolded in the next migration set.';
            COMMENT ON COLUMN stock_allocations.suggested_bin_id IS
              'FK to bins(id) pending — bins (Doc 02 §7.3) is fully specified but not yet scaffolded in any session.';

            CREATE INDEX stock_allocations_sku_active_idx
              ON stock_allocations (sku_id, location_id, batch_id)
              WHERE status IN ('allocated','picked');
            CREATE INDEX stock_allocations_reaper_idx ON stock_allocations (allocated_at)
              WHERE status = 'allocated';
            CREATE INDEX stock_allocations_bin_idx ON stock_allocations (suggested_bin_id)
              WHERE suggested_bin_id IS NOT NULL AND status IN ('allocated','picked');
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_allocations CASCADE');
    }
};
