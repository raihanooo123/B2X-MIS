<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.3 — bins. Advisory picking-slip hints, never locked during
 * allocation (§7.4: "suggested_bin_id is advisory and is never locked" —
 * a picker taking goods from a different bin is not an error).
 * `location_id` is a real foreign key — `locations` already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE bins (
              id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              location_id   bigint   NOT NULL REFERENCES locations (id),
              code          text     NOT NULL,
              walk_sequence integer,

              CONSTRAINT bins_location_code_uq UNIQUE (location_id, code)
            );

            CREATE INDEX bins_walk_idx ON bins (location_id, walk_sequence)
              WHERE walk_sequence IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS bins CASCADE');
    }
};
