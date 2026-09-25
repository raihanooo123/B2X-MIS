<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.6 (signed off 2026-09-20) — shipment_lines. `sku_id` is
 * denormalised alongside `order_line_id` for the pick-list and reporting
 * paths; `order_lines.sku_id` remains authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipment_lines (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              shipment_id        bigint  NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
              order_line_id      bigint  NOT NULL REFERENCES order_lines (id),
              sku_id             bigint  NOT NULL REFERENCES skus (id),
              dispatched_base_qty integer NOT NULL,
              created_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT shipment_lines_shipment_order_line_uq UNIQUE (shipment_id, order_line_id),
              CONSTRAINT shipment_lines_qty_chk CHECK (dispatched_base_qty > 0)
            );

            CREATE INDEX shipment_lines_order_line_idx ON shipment_lines (order_line_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS shipment_lines CASCADE');
    }
};
