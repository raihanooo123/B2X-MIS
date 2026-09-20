<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.4 / §7.3 — enforces `stock_allocations.suggested_bin_id
 * REFERENCES bins (id)` now that `bins` exists. The column, its type
 * and its index shape were already created with `stock_allocations`;
 * this only adds the constraint that was deferred pending this table.
 * This is the last remaining deferred FK in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stock_allocations ADD CONSTRAINT stock_allocations_suggested_bin_id_fk
              FOREIGN KEY (suggested_bin_id) REFERENCES bins (id)
        SQL);

        DB::statement('COMMENT ON COLUMN stock_allocations.suggested_bin_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_allocations DROP CONSTRAINT IF EXISTS stock_allocations_suggested_bin_id_fk');
    }
};
