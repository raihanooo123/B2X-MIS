<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.5 — tax_classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE tax_classes (
              id   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code text NOT NULL,
              name text NOT NULL,
              xero_tax_type text,

              CONSTRAINT tax_classes_code_uq UNIQUE (code)
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS tax_classes CASCADE');
    }
};
