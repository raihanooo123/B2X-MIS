<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.6 (signed off 2026-09-20) — shipment_line_serials. The
 * specific serials that left on a shipment line (04 §6.1).
 * `shipment_line_serials_serial_idx` answers "which shipment did this
 * serial go out on" (§9 rule 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipment_line_serials (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              shipment_line_id  bigint  NOT NULL REFERENCES shipment_lines (id) ON DELETE CASCADE,
              serial_id         bigint  NOT NULL REFERENCES stock_serials (id),

              CONSTRAINT shipment_line_serials_line_serial_uq UNIQUE (shipment_line_id, serial_id)
            );

            CREATE INDEX shipment_line_serials_serial_idx ON shipment_line_serials (serial_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS shipment_line_serials CASCADE');
    }
};
