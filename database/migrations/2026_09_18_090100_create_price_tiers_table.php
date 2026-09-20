<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.3 — price_tiers. `price_tiers_default_uq` is a partial unique
 * index on the constant `(true)`, the idiomatic Postgres way to express a
 * system-wide singleton constraint: exactly one default tier, ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE price_tiers (
              id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code       text     NOT NULL,
              name       text     NOT NULL,
              position   smallint NOT NULL DEFAULT 0,
              is_default boolean  NOT NULL DEFAULT false,

              CONSTRAINT price_tiers_code_uq UNIQUE (code)
            );

            CREATE UNIQUE INDEX price_tiers_default_uq ON price_tiers ((true)) WHERE is_default;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS price_tiers CASCADE');
    }
};
