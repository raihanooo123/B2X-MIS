<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.3 — stock_levels, a rebuildable projection over
 * stock_movements (§7.2). No surrogate `id`: the natural key
 * (sku_id, location_id, batch_id) *is* the row's identity — nothing
 * foreign-keys to this table, and the unique index is the lookup path
 * for the FOR UPDATE allocation transaction. `UNIQUE NULLS NOT DISTINCT`
 * means batch_id IS NULL ("not batch-tracked") collapses to exactly one
 * row per (sku, location), with no MySQL-era sentinel batch row.
 *
 * `batch_id` is intentionally NOT a foreign key yet: `batches` (§7.5)
 * has full DDL in Doc 02 but has not been scaffolded in any session's
 * scope. `last_movement_id` is a plain bigint with no REFERENCES clause
 * in the doc itself — not a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_levels (
              sku_id                 bigint      NOT NULL REFERENCES skus (id) ON DELETE CASCADE,
              location_id            bigint      NOT NULL REFERENCES locations (id),
              batch_id               bigint,
              on_hand_base_qty       integer     NOT NULL DEFAULT 0,
              allocated_base_qty     integer     NOT NULL DEFAULT 0,
              available_base_qty     integer GENERATED ALWAYS AS
                                       (on_hand_base_qty - allocated_base_qty) STORED,
              incoming_base_qty      integer     NOT NULL DEFAULT 0,
              reorder_point_base_qty integer     NOT NULL DEFAULT 0,
              reorder_qty_base_qty   integer     NOT NULL DEFAULT 0,
              version                bigint      NOT NULL DEFAULT 0,
              last_movement_id       bigint,
              updated_at             timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT stock_levels_identity_uq
                UNIQUE NULLS NOT DISTINCT (sku_id, location_id, batch_id),
              CONSTRAINT stock_levels_on_hand_chk   CHECK (on_hand_base_qty   >= 0),
              CONSTRAINT stock_levels_allocated_chk CHECK (allocated_base_qty >= 0)
            );

            COMMENT ON COLUMN stock_levels.batch_id IS
              'FK to batches(id) pending — batches (Doc 02 §7.5) is fully specified but not yet scaffolded in any session.';

            CREATE INDEX stock_levels_loc_available_idx
              ON stock_levels (location_id, available_base_qty)
              WHERE available_base_qty > 0;
            CREATE INDEX stock_levels_reorder_idx
              ON stock_levels (location_id, reorder_point_base_qty, available_base_qty)
              WHERE reorder_point_base_qty > 0;
            CREATE INDEX stock_levels_sku_idx
              ON stock_levels (sku_id) INCLUDE (location_id, batch_id, available_base_qty);
            CREATE INDEX stock_levels_batch_idx ON stock_levels (batch_id)
              WHERE batch_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_levels CASCADE');
    }
};
