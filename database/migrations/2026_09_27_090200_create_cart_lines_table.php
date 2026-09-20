<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.3 (signed off 2026-09-20) — cart_lines. Deliberately not shaped
 * like order_lines: no price/tax/cost snapshot, because nothing is final yet
 * — prices are recomputed live via /pricing/bulk-resolve (03 §8) on every
 * render. pack_base_units is refreshed by the application on a pack change,
 * not an immutable snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE cart_lines (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id        text        NOT NULL,
              cart_id          bigint      NOT NULL REFERENCES carts (id) ON DELETE CASCADE,
              sku_id           bigint      NOT NULL REFERENCES skus (id),
              pack_id          bigint      NOT NULL REFERENCES packs (id),
              pack_qty         integer     NOT NULL,
              pack_base_units  integer     NOT NULL,
              base_qty         integer     NOT NULL,
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT cart_lines_public_id_uq UNIQUE (public_id),
              CONSTRAINT cart_lines_cart_sku_pack_uq UNIQUE (cart_id, sku_id, pack_id),
              CONSTRAINT cart_lines_qty_pos_chk CHECK (pack_qty > 0 AND pack_base_units > 0),
              CONSTRAINT cart_lines_base_qty_chk CHECK (base_qty = pack_qty * pack_base_units)
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS cart_lines CASCADE');
    }
};
