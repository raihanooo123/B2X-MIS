<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.5 (amendment, signed off 2026-09-21) — delivery_zone_postcodes.
 * Maps UK postcode area + district ranges to a zone.
 * delivery_zone_postcodes_uq is exactly the key the §8.4 row specifies —
 * it does not itself prevent overlapping (not identical) ranges across
 * different zones; that is a data-quality concern for the admin tool,
 * not a structural invariant, so no EXCLUDE is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE delivery_zone_postcodes (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              delivery_zone_id bigint   NOT NULL REFERENCES delivery_zones (id) ON DELETE CASCADE,
              area             text     NOT NULL,
              district_from    smallint NOT NULL,
              district_to      smallint NOT NULL,
              created_at       timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT delivery_zone_postcodes_uq UNIQUE (area, district_from, district_to),
              CONSTRAINT delivery_zone_postcodes_range_chk CHECK (district_to >= district_from)
            );

            CREATE INDEX delivery_zone_postcodes_zone_idx ON delivery_zone_postcodes (delivery_zone_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS delivery_zone_postcodes CASCADE');
    }
};
