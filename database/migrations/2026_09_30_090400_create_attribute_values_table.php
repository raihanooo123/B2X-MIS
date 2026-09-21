<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.7 — attribute_values.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attribute_values (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              attribute_id bigint   NOT NULL REFERENCES attributes (id) ON DELETE CASCADE,
              value        text     NOT NULL,
              slug         text     NOT NULL,
              swatch_hex   char(7),
              position     smallint NOT NULL DEFAULT 0,

              CONSTRAINT attribute_values_uq UNIQUE (attribute_id, slug)
            );

            CREATE INDEX attribute_values_position_idx
              ON attribute_values (attribute_id, position) INCLUDE (value, swatch_hex);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS attribute_values CASCADE');
    }
};
