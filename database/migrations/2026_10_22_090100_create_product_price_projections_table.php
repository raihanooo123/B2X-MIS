<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02 §29 (signed off 2026-10-05) — the price sort key for the storefront.
 *
 * A rebuildable read model, like `stock_levels`: each active product's
 * "from" price exactly as its card shows a logged-out visitor (base list,
 * qty 1, GB). Nothing charges or displays from it (invariant 3 holds: no
 * price on `products` or `skus`); ProductPriceProjector writes it and
 * StorefrontCatalogue reads it, nothing else.
 *
 * Empty at creation: `storefront:refresh-price-projection` fills it (and
 * runs nightly). Until then price sorts list every product as unpriced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE product_price_projections (
              product_id          bigint      PRIMARY KEY REFERENCES products (id) ON DELETE CASCADE,
              from_sku_id         bigint      REFERENCES skus (id) ON DELETE CASCADE,
              price_list_id       bigint      REFERENCES price_lists (id),
              from_unit_net_e4    bigint,
              tax_rate_bp         integer,
              from_unit_gross_e4  bigint,
              stale_after         timestamptz,
              refreshed_at        timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT product_price_projections_shape_chk CHECK (
                  (from_sku_id IS NULL AND price_list_id IS NULL AND from_unit_net_e4 IS NULL
                   AND tax_rate_bp IS NULL AND from_unit_gross_e4 IS NULL)
               OR (from_sku_id IS NOT NULL AND price_list_id IS NOT NULL AND from_unit_net_e4 >= 0
                   AND tax_rate_bp BETWEEN 0 AND 10000 AND from_unit_gross_e4 >= from_unit_net_e4)
              )
            );

            CREATE INDEX product_price_projections_gross_idx
              ON product_price_projections (from_unit_gross_e4 ASC NULLS LAST, product_id);
            CREATE INDEX product_price_projections_net_idx
              ON product_price_projections (from_unit_net_e4 ASC NULLS LAST, product_id);
            CREATE INDEX product_price_projections_stale_idx
              ON product_price_projections (stale_after) WHERE stale_after IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS product_price_projections');
    }
};
