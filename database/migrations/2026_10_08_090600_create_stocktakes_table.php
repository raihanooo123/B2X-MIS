<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.7 (signed off 2026-09-20) — stocktakes. A counting session per
 * location (04 §7.4, 05.5 §8). Nothing touches the ledger until posting.
 * `stocktakes_open_idx` is partial on the live statuses (§9 rule 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stocktakes (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id          text        NOT NULL,
              location_id        bigint      NOT NULL REFERENCES locations (id),
              status             text        NOT NULL DEFAULT 'open',
              is_blind           boolean     NOT NULL DEFAULT false,
              started_by_user_id bigint      REFERENCES users (id),
              posted_by_user_id  bigint      REFERENCES users (id),
              started_at         timestamptz NOT NULL DEFAULT now(),
              posted_at          timestamptz,
              note               text,
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT stocktakes_public_id_uq UNIQUE (public_id),
              CONSTRAINT stocktakes_status_chk CHECK (status IN
                ('open','review','posted','cancelled'))
            );

            CREATE INDEX stocktakes_location_status_idx ON stocktakes (location_id, status);
            CREATE INDEX stocktakes_open_idx ON stocktakes (location_id)
              WHERE status IN ('open','review');
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stocktakes CASCADE');
    }
};
