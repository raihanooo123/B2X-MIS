<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.7 §4 (DDL signed off 2026-09-25) — suppliers. `default_incoterm`
 * decides whether freight is apportioned into landed cost (§8.4): under
 * DDP the supplier's price already includes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE suppliers (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code             text        NOT NULL,
              name             text        NOT NULL,
              country_code     char(2)     NOT NULL,
              default_currency char(3)     NOT NULL DEFAULT 'GBP',
              default_incoterm text        NOT NULL DEFAULT 'FOB',
              lead_time_days   smallint,
              payment_terms    text,
              contact_name     text,
              contact_email    citext,
              contact_phone    text,
              address          jsonb,
              status           text        NOT NULL DEFAULT 'active',
              note             text,
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT suppliers_code_uq UNIQUE (code),
              CONSTRAINT suppliers_incoterm_chk CHECK (default_incoterm IN
                ('EXW','FOB','CIF','CFR','DAP','DDP')),
              CONSTRAINT suppliers_status_chk CHECK (status IN ('active','on_hold','archived'))
            );

            CREATE INDEX suppliers_active_name_idx ON suppliers (name) WHERE status = 'active';
            CREATE INDEX suppliers_country_idx     ON suppliers (country_code)
              WHERE status = 'active';
            CREATE INDEX suppliers_name_trgm_idx   ON suppliers USING gin (name gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS suppliers CASCADE');
    }
};
