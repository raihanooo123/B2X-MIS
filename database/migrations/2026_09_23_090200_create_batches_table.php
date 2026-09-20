<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.5 — batches, batch & expiry tracking. No sentinel row:
 * sku_id is NOT NULL, since the MySQL-era sentinel existed only to give
 * the id=0 row a place to not belong to a SKU.
 *
 * `purchase_order_id` and `container_id` are plain bigint with no
 * REFERENCES clause in the doc itself (purchase_orders/containers are
 * Phase 3) — not a gap, that's how the doc specifies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE batches (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              sku_id             bigint      NOT NULL REFERENCES skus (id),
              batch_code         text        NOT NULL,
              supplier_batch_ref text,
              purchase_order_id  bigint,
              container_id       bigint,
              sku_cost_id        bigint      REFERENCES sku_costs (id),
              unit_cost_e4       bigint,
              manufactured_on    date,
              expires_on         date,
              best_before_on     date,
              received_at        timestamptz,
              status             text        NOT NULL DEFAULT 'active',
              recall_reference   text,
              note               text,
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT batches_sku_code_uq UNIQUE (sku_id, batch_code),
              CONSTRAINT batches_status_chk CHECK (status IN
                ('active','quarantined','expired','recalled','depleted')),
              CONSTRAINT batches_dates_chk CHECK (expires_on IS NULL
                                                  OR manufactured_on IS NULL
                                                  OR expires_on >= manufactured_on)
            );

            -- FEFO: active batches of a SKU, earliest expiry first. Partial and covering.
            CREATE INDEX batches_fefo_idx ON batches (sku_id, expires_on NULLS LAST, id)
              INCLUDE (batch_code, unit_cost_e4) WHERE status = 'active';
            CREATE INDEX batches_expiry_sweep_idx ON batches (expires_on)
              WHERE status = 'active' AND expires_on IS NOT NULL;
            CREATE INDEX batches_recall_idx ON batches (recall_reference)
              WHERE recall_reference IS NOT NULL;
            CREATE INDEX batches_po_idx ON batches (purchase_order_id)
              WHERE purchase_order_id IS NOT NULL;
            CREATE INDEX batches_code_trgm_idx ON batches USING gin (batch_code gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS batches CASCADE');
    }
};
