<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §27 (signed off 2026-10-04, 05.15 §9 A9) — the estimated cost of
 * returning a consumer's pallet consignment, saved at placement as the
 * customer was told it (CCR Sch. 2 para (l)). NULL for everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders ADD COLUMN return_cost_estimate_gross_minor bigint;

            ALTER TABLE orders ADD CONSTRAINT orders_return_cost_estimate_chk CHECK (
              return_cost_estimate_gross_minor IS NULL
              OR (return_cost_estimate_gross_minor >= 0 AND company_id IS NULL)
            ) NOT VALID;
            ALTER TABLE orders VALIDATE CONSTRAINT orders_return_cost_estimate_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_return_cost_estimate_chk;
            ALTER TABLE orders DROP COLUMN IF EXISTS return_cost_estimate_gross_minor;
        SQL);
    }
};
