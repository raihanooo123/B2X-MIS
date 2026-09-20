<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.3 — locations. Present from day one even though launch is
 * single-warehouse: adding a location dimension later would mean
 * rewriting every stock query, every allocation path and every report.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE locations (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code         text    NOT NULL,
              name         text    NOT NULL,
              location_type text   NOT NULL DEFAULT 'warehouse',
              is_sellable  boolean NOT NULL DEFAULT true,
              is_default   boolean NOT NULL DEFAULT false,

              CONSTRAINT locations_code_uq UNIQUE (code),
              CONSTRAINT locations_type_chk
                CHECK (location_type IN ('warehouse','collection','virtual','quarantine'))
            );

            CREATE UNIQUE INDEX locations_default_uq ON locations ((true)) WHERE is_default;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS locations CASCADE');
    }
};
