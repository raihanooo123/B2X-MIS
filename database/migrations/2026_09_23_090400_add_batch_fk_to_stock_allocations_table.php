<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.4 / §7.5 — enforces `stock_allocations.batch_id REFERENCES
 * batches (id)` now that `batches` exists. The column, its type and its
 * role in `stock_allocations_identity_uq` were already created with
 * `stock_allocations`; this only adds the constraint that was deferred
 * pending this table. `suggested_bin_id` remains deferred — `bins` is
 * still out of scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stock_allocations ADD CONSTRAINT stock_allocations_batch_id_fk
              FOREIGN KEY (batch_id) REFERENCES batches (id)
        SQL);

        DB::statement('COMMENT ON COLUMN stock_allocations.batch_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_allocations DROP CONSTRAINT IF EXISTS stock_allocations_batch_id_fk');
    }
};
