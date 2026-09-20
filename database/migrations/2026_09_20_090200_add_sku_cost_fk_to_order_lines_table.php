<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.3 / §6.6 — enforces `order_lines.sku_cost_id REFERENCES
 * sku_costs (id)` now that `sku_costs` exists. The column, its type and
 * its index shape were already created with `order_lines`; this only
 * adds the constraint that was deferred pending this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_sku_cost_id_fk
              FOREIGN KEY (sku_cost_id) REFERENCES sku_costs (id)
        SQL);

        DB::statement('COMMENT ON COLUMN order_lines.sku_cost_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT IF EXISTS order_lines_sku_cost_id_fk');
    }
};
