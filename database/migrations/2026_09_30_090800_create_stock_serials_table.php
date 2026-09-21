<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.6 — stock_serials. Uniqueness is scoped (sku_id, serial_number),
 * not global — two manufacturers can legitimately issue the same serial
 * string. `stock_serials_assigned_chk` makes a dispatched serial with no
 * order line structurally impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_serials (
              id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              sku_id                 bigint      NOT NULL REFERENCES skus (id),
              serial_number          text        NOT NULL,
              batch_id               bigint      REFERENCES batches (id),
              location_id            bigint      REFERENCES locations (id),
              bin_id                 bigint      REFERENCES bins (id),
              status                 text        NOT NULL DEFAULT 'in_stock',
              order_line_id          bigint      REFERENCES order_lines (id),
              rma_line_id            bigint,
              received_movement_id   bigint,
              dispatched_movement_id bigint,
              warranty_expires_on    date,
              received_at            timestamptz,
              dispatched_at          timestamptz,
              created_at             timestamptz NOT NULL DEFAULT now(),
              updated_at             timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT stock_serials_sku_number_uq UNIQUE (sku_id, serial_number),
              CONSTRAINT stock_serials_status_chk CHECK (status IN
                ('expected','in_stock','allocated','picked','dispatched',
                 'returned','quarantined','written_off')),
              CONSTRAINT stock_serials_assigned_chk CHECK (
                status NOT IN ('allocated','picked','dispatched')
                OR order_line_id IS NOT NULL)
            );

            -- picking: in-stock serials for a SKU at a location and batch, oldest first
            CREATE INDEX stock_serials_pick_idx
              ON stock_serials (sku_id, location_id, batch_id, id)
              INCLUDE (serial_number) WHERE status = 'in_stock';
            CREATE INDEX stock_serials_number_trgm ON stock_serials USING gin
              (serial_number gin_trgm_ops);
            CREATE INDEX stock_serials_number_idx   ON stock_serials (serial_number);
            CREATE INDEX stock_serials_order_line_idx ON stock_serials (order_line_id)
              WHERE order_line_id IS NOT NULL;
            CREATE INDEX stock_serials_rma_line_idx ON stock_serials (rma_line_id)
              WHERE rma_line_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_serials CASCADE');
    }
};
