<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.2 / §8.5 — enforces `orders.delivery_zone_id REFERENCES
 * delivery_zones (id)` now that `delivery_zones` exists. The column, its
 * type and its use in the orders DDL were already created with `orders`;
 * this only adds the constraint that was deferred pending this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_delivery_zone_id_fk
              FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones (id)
        SQL);

        DB::statement('COMMENT ON COLUMN orders.delivery_zone_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_delivery_zone_id_fk');
    }
};
