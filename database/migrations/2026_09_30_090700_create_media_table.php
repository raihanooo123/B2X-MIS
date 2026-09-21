<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.8 — media. `variants jsonb` caches generated derivative paths
 * (thumb/card/zoom, WebP/AVIF) so rendering needs no second query.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE media (
              id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              product_id    bigint      REFERENCES products (id) ON DELETE CASCADE,
              sku_id        bigint      REFERENCES skus (id)     ON DELETE CASCADE,
              disk          text        NOT NULL DEFAULT 's3',
              path          text        NOT NULL,
              original_name text,
              mime_type     text        NOT NULL,
              size_bytes    integer     NOT NULL,
              width_px      integer,
              height_px     integer,
              alt_text      text,
              media_type    text        NOT NULL DEFAULT 'image',
              variants      jsonb,
              position      smallint    NOT NULL DEFAULT 0,
              created_at    timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT media_type_chk CHECK (media_type IN ('image','video','document')),
              CONSTRAINT media_owner_chk CHECK (product_id IS NOT NULL OR sku_id IS NOT NULL)
            );

            CREATE INDEX media_product_idx ON media (product_id, position)
              WHERE product_id IS NOT NULL;
            CREATE INDEX media_sku_idx     ON media (sku_id, position) WHERE sku_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS media CASCADE');
    }
};
