<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.6 (signed off 2026-09-20) — shipment_line_batches. One order
 * line is routinely split across several batches (04 §5.3).
 * `shipment_line_batches_batch_idx` is the reverse direction (§9 rule 5):
 * given a batch, every shipment it went out on — the recall trace and
 * 05.4's returned-batch recovery read through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipment_line_batches (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              shipment_line_id  bigint  NOT NULL REFERENCES shipment_lines (id) ON DELETE CASCADE,
              batch_id          bigint  NOT NULL REFERENCES batches (id),
              base_qty          integer NOT NULL,

              CONSTRAINT shipment_line_batches_line_batch_uq UNIQUE (shipment_line_id, batch_id),
              CONSTRAINT shipment_line_batches_qty_chk CHECK (base_qty > 0)
            );

            CREATE INDEX shipment_line_batches_batch_idx ON shipment_line_batches (batch_id)
              INCLUDE (shipment_line_id, base_qty);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS shipment_line_batches CASCADE');
    }
};
