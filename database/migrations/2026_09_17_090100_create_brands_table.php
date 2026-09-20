<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.9 (amendment, signed off 2026-09-17) — brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE brands (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id        text        NOT NULL,
              name             text        NOT NULL,
              slug             text        NOT NULL,
              description      text,
              logo_media_id    bigint,
              meta_title       text,
              meta_description text,
              status           text        NOT NULL DEFAULT 'active',
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT brands_status_chk CHECK (status IN ('active','hidden','archived'))
            );

            CREATE UNIQUE INDEX brands_slug_uq      ON brands (slug);
            CREATE UNIQUE INDEX brands_public_id_uq ON brands (public_id);
            CREATE INDEX brands_active_idx   ON brands (name) WHERE status = 'active';
            CREATE INDEX brands_name_trgm_idx ON brands USING gin (name gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS brands CASCADE');
    }
};
