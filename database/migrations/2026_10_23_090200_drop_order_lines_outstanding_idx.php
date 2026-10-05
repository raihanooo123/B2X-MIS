<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05.10 §2.4: the old picking index counted cancelled quantity as still to
 * send. The code reads `order_lines_outstanding_v2_idx`'s predicate from
 * the previous migration on, so the old one goes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS order_lines_outstanding_idx');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS order_lines_outstanding_idx ON order_lines (order_id)
              INCLUDE (sku_id, base_qty, dispatched_base_qty)
              WHERE dispatched_base_qty < base_qty
        SQL);
    }
};
