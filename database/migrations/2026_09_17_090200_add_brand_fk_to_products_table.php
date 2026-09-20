<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.4 / §5.9 — enforces `products.brand_id REFERENCES brands (id)`
 * now that `brands` exists (§5.9, signed off 2026-09-17). The column
 * itself, its type and its indexes were already created with `products`;
 * this only adds the constraint that was deferred pending that sign-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_brand_id_fk
              FOREIGN KEY (brand_id) REFERENCES brands (id)
        SQL);

        DB::statement('COMMENT ON COLUMN products.brand_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_brand_id_fk');
    }
};
