<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §24.2 (signed off 2026-09-26) — stocktake_line_serials. One row
 * per serial scanned into a stocktake line, so missing serials are listed
 * by number, not as a quantity (05.5 §8). `serial_id` is NULL for a
 * serial the system has never seen; the number is kept either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stocktake_line_serials (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              stocktake_line_id   bigint      NOT NULL REFERENCES stocktake_lines (id) ON DELETE CASCADE,
              serial_number       text        NOT NULL,
              serial_id           bigint      REFERENCES stock_serials (id),
              scanned_by_user_id  bigint      REFERENCES users (id),
              scanned_at          timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT stocktake_line_serials_line_number_uq UNIQUE (stocktake_line_id, serial_number)
            );

            CREATE INDEX stocktake_line_serials_serial_idx ON stocktake_line_serials (serial_id)
              WHERE serial_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stocktake_line_serials CASCADE');
    }
};
