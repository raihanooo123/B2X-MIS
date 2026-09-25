<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.7 (signed off 2026-09-20) — stocktake_lines.
 *
 * `expected_base_qty` is filled **at posting**, not at count entry (04
 * §7.4), so `variance_base_qty` is NULL — "not yet reconciled" — until
 * then. `posted_movement_id` has no REFERENCES, like
 * `stock_levels.last_movement_id`: `stock_movements` has a composite
 * `(id, occurred_at)` key. "A posted line with a nonzero variance carries a
 * reason" depends on the parent's status and is enforced by the posting
 * transaction, not a CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stocktake_lines (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              stocktake_id          bigint  NOT NULL REFERENCES stocktakes (id) ON DELETE CASCADE,
              sku_id                bigint  NOT NULL REFERENCES skus (id),
              batch_id              bigint  REFERENCES batches (id),
              counted_base_qty      integer NOT NULL,
              expected_base_qty     integer,
              variance_base_qty     integer GENERATED ALWAYS AS
                                      (counted_base_qty - expected_base_qty) STORED,
              reason_code           text,
              posted_movement_id    bigint,
              counted_by_user_id    bigint  REFERENCES users (id),
              counted_at            timestamptz NOT NULL DEFAULT now(),
              created_at            timestamptz NOT NULL DEFAULT now(),
              updated_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT stocktake_lines_identity_uq
                UNIQUE NULLS NOT DISTINCT (stocktake_id, sku_id, batch_id),
              CONSTRAINT stocktake_lines_counted_chk CHECK (counted_base_qty >= 0)
            );

            CREATE INDEX stocktake_lines_pending_idx ON stocktake_lines (stocktake_id)
              WHERE expected_base_qty IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stocktake_lines CASCADE');
    }
};
