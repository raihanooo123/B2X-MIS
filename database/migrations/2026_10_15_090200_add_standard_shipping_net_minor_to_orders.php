<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §26.3 (signed off 2026-10-04, 05.15 §9 A3) — the least expensive
 * standard delivery charge quoted at placement, which caps a consumer's
 * delivery refund on cancellation (05.15 §7.2). NULL for trade orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders ADD COLUMN standard_shipping_net_minor bigint;

            ALTER TABLE orders ADD CONSTRAINT orders_standard_shipping_chk CHECK (
              standard_shipping_net_minor IS NULL
              OR (standard_shipping_net_minor >= 0 AND standard_shipping_net_minor <= shipping_net_minor)
            ) NOT VALID;
            ALTER TABLE orders VALIDATE CONSTRAINT orders_standard_shipping_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_standard_shipping_chk;
            ALTER TABLE orders DROP COLUMN IF EXISTS standard_shipping_net_minor;
        SQL);
    }
};
