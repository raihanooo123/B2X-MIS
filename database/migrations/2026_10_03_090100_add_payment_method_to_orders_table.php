<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §18 (signed off 2026-09-24) — orders.payment_method: how the
 * order is being paid, recorded at checkout (06 §9.3). Values mirror
 * App\Domain\Ordering\PaymentMethod.
 *
 * Nullable: orders placed before this column cannot be known, except
 * on-account ones, whose `payment_status` already says so — those are
 * backfilled. Adding a nullable column with no default is a catalogue-only
 * change in PostgreSQL (07 §11.1: no table rewrite, no long lock).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders ADD COLUMN payment_method text;

            UPDATE orders SET payment_method = 'on_account' WHERE payment_status = 'on_account';

            ALTER TABLE orders ADD CONSTRAINT orders_payment_method_chk CHECK (
              payment_method IS NULL OR payment_method IN ('card','bacs','on_account','prepay')
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_method_chk;
            ALTER TABLE orders DROP COLUMN IF EXISTS payment_method;
        SQL);
    }
};
