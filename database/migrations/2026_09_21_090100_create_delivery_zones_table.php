<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.5 (amendment, signed off 2026-09-21) — delivery_zones.
 * No `public_id` — like price_tiers/tax_classes, this is internal
 * operational configuration, never exposed in a customer-facing URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE delivery_zones (
              id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code       text     NOT NULL,
              name       text     NOT NULL,
              status     text     NOT NULL DEFAULT 'active',
              position   smallint NOT NULL DEFAULT 0,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT delivery_zones_code_uq UNIQUE (code),
              CONSTRAINT delivery_zones_status_chk CHECK (status IN ('active','archived'))
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS delivery_zones CASCADE');
    }
};
