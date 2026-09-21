<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.7 — product_variant_axes. Which attributes distinguish the
 * variants of one `product_type = 'variant'` product (02 §5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE product_variant_axes (
              product_id   bigint   NOT NULL REFERENCES products (id) ON DELETE CASCADE,
              attribute_id bigint   NOT NULL REFERENCES attributes (id),
              position     smallint NOT NULL DEFAULT 0,
              PRIMARY KEY (product_id, attribute_id)
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS product_variant_axes CASCADE');
    }
};
