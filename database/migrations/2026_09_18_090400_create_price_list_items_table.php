<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.4 — price_list_items, the break table. The single most
 * performance-critical table in the application (order-pad resolution:
 * 50-100 SKUs against 2-5 candidate price lists in one page load).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE price_list_items (
              id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              price_list_id  bigint      NOT NULL REFERENCES price_lists (id) ON DELETE CASCADE,
              sku_id         bigint      NOT NULL REFERENCES skus (id)        ON DELETE CASCADE,
              min_base_qty   integer     NOT NULL DEFAULT 1,
              unit_price_e4  bigint      NOT NULL,
              created_at     timestamptz NOT NULL DEFAULT now(),
              updated_at     timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT price_list_items_uq UNIQUE (price_list_id, sku_id, min_base_qty),
              CONSTRAINT price_list_items_qty_chk   CHECK (min_base_qty >= 1),
              CONSTRAINT price_list_items_price_chk CHECK (unit_price_e4 >= 0)
            );

            -- the bulk order-pad path
            CREATE INDEX price_list_items_resolve_idx
              ON price_list_items (price_list_id, sku_id, min_base_qty DESC)
              INCLUDE (unit_price_e4);

            -- the admin price editor and audit path
            CREATE INDEX price_list_items_by_sku_idx
              ON price_list_items (sku_id, price_list_id, min_base_qty DESC)
              INCLUDE (unit_price_e4);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS price_list_items CASCADE');
    }
};
