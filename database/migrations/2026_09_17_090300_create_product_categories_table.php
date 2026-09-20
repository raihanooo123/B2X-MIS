<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.9 (amendment, signed off 2026-09-17) — product_categories.
 * Secondary/cross-listing category membership; a product's primary
 * category stays the direct `products.primary_category_id` FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE product_categories (
              product_id  bigint      NOT NULL REFERENCES products (id)   ON DELETE CASCADE,
              category_id bigint      NOT NULL REFERENCES categories (id) ON DELETE CASCADE,
              position    smallint    NOT NULL DEFAULT 0,
              created_at  timestamptz NOT NULL DEFAULT now(),

              PRIMARY KEY (product_id, category_id)
            );

            CREATE INDEX product_categories_category_idx
              ON product_categories (category_id, product_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS product_categories CASCADE');
    }
};
