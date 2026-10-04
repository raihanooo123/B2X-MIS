<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §26.1 (signed off 2026-10-04, 05.15 §9 A1) — a guest order has
 * no company and no user; `guest_email` names its customer. Every order
 * names one (`orders_customer_chk`). The partial index serves the claim
 * of a guest's orders once their email is verified (05.15 §6.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders ADD COLUMN guest_email text;

            ALTER TABLE orders ADD CONSTRAINT orders_customer_chk CHECK (
              company_id IS NOT NULL OR user_id IS NOT NULL OR guest_email IS NOT NULL
            ) NOT VALID;
            ALTER TABLE orders VALIDATE CONSTRAINT orders_customer_chk;

            CREATE INDEX orders_guest_claim_idx ON orders (lower(guest_email))
              WHERE user_id IS NULL AND guest_email IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS orders_guest_claim_idx;
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_customer_chk;
            ALTER TABLE orders DROP COLUMN IF EXISTS guest_email;
        SQL);
    }
};
