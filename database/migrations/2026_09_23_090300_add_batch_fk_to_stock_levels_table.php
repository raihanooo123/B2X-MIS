<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.3 / §7.5 — enforces `stock_levels.batch_id REFERENCES
 * batches (id)` now that `batches` exists. The column, its type and its
 * role in `stock_levels_identity_uq` were already created with
 * `stock_levels`; this only adds the constraint that was deferred
 * pending this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_batch_id_fk
              FOREIGN KEY (batch_id) REFERENCES batches (id)
        SQL);

        DB::statement('COMMENT ON COLUMN stock_levels.batch_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_batch_id_fk');
    }
};
