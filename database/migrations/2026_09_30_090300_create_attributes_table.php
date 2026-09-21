<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.7 — attributes. Variant axes and filterable facets both live
 * here; `is_variant_axis`/`is_filterable` distinguish the two uses of one
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attributes (
              id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code            text     NOT NULL,
              name            text     NOT NULL,
              data_type       text     NOT NULL DEFAULT 'select',
              unit            text,
              is_variant_axis boolean  NOT NULL DEFAULT false,
              is_filterable   boolean  NOT NULL DEFAULT false,
              position        smallint NOT NULL DEFAULT 0,

              CONSTRAINT attributes_code_uq UNIQUE (code),
              CONSTRAINT attributes_type_chk
                CHECK (data_type IN ('select','text','integer','decimal','boolean'))
            );

            CREATE INDEX attributes_filterable_idx ON attributes (position) WHERE is_filterable;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS attributes CASCADE');
    }
};
