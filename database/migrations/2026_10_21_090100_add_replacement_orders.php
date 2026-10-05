<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05.4 §14.3 (signed off 2026-10-05, 02 §30) — replacement orders.
 *
 * `orders.order_kind` marks a replacement, which has zero totals and
 * `payment_status = 'not_required'` (and nothing else may have that
 * status). Its lines are `price_source = 'replacement'`, zero-priced, and
 * name the faulty line they replace. One replacement per RMA.
 *
 * Every existing row is a `sale` with a payment status other than
 * `not_required` and no replacement lines, so each VALIDATE succeeds.
 * NOT VALID then VALIDATE keeps the lock short (02 §2.5, 07 §11.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders ADD COLUMN order_kind text NOT NULL DEFAULT 'sale';
            ALTER TABLE orders ADD CONSTRAINT orders_kind_chk
              CHECK (order_kind IN ('sale','replacement')) NOT VALID;
            ALTER TABLE orders VALIDATE CONSTRAINT orders_kind_chk;

            ALTER TABLE orders DROP CONSTRAINT orders_payment_status_chk;
            ALTER TABLE orders ADD CONSTRAINT orders_payment_status_chk CHECK (payment_status IN
              ('unpaid','deposit_paid','paid','part_refunded','refunded','on_account','not_required')) NOT VALID;
            ALTER TABLE orders VALIDATE CONSTRAINT orders_payment_status_chk;

            ALTER TABLE orders ADD CONSTRAINT orders_replacement_chk CHECK (
              (order_kind = 'sale' AND payment_status <> 'not_required')
              OR (order_kind = 'replacement'
                  AND payment_status = 'not_required' AND payment_method IS NULL
                  AND subtotal_net_minor = 0 AND discount_net_minor = 0
                  AND shipping_net_minor = 0 AND shipping_tax_minor = 0
                  AND tax_minor = 0 AND total_gross_minor = 0
                  AND spend_break_discount_minor = 0 AND account_credit_applied_minor = 0)
            ) NOT VALID;
            ALTER TABLE orders VALIDATE CONSTRAINT orders_replacement_chk;

            ALTER TABLE order_lines DROP CONSTRAINT order_lines_price_source_chk;
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_price_source_chk CHECK (price_source IN
              ('contract','customer','promotion','tier','base','manual','replacement')) NOT VALID;
            ALTER TABLE order_lines VALIDATE CONSTRAINT order_lines_price_source_chk;

            ALTER TABLE order_lines ADD COLUMN replaces_order_line_id bigint REFERENCES order_lines (id);
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_replacement_chk CHECK (
              (price_source <> 'replacement' AND replaces_order_line_id IS NULL)
              OR (price_source = 'replacement' AND replaces_order_line_id IS NOT NULL
                  AND unit_price_net_e4 = 0 AND line_discount_minor = 0 AND line_spend_discount_minor = 0
                  AND line_net_minor = 0 AND line_tax_minor = 0 AND line_gross_minor = 0)
            ) NOT VALID;
            ALTER TABLE order_lines VALIDATE CONSTRAINT order_lines_replacement_chk;

            CREATE INDEX order_lines_replaces_idx ON order_lines (replaces_order_line_id)
              WHERE replaces_order_line_id IS NOT NULL;

            CREATE UNIQUE INDEX rmas_replacement_order_uq ON rmas (replacement_order_id)
              WHERE replacement_order_id IS NOT NULL;
            ALTER TABLE rmas ADD CONSTRAINT rmas_replacement_chk CHECK (
              replacement_order_id IS NULL OR resolution_type = 'replacement') NOT VALID;
            ALTER TABLE rmas VALIDATE CONSTRAINT rmas_replacement_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE rmas DROP CONSTRAINT IF EXISTS rmas_replacement_chk;
            DROP INDEX IF EXISTS rmas_replacement_order_uq;
            DROP INDEX IF EXISTS order_lines_replaces_idx;
            ALTER TABLE order_lines DROP CONSTRAINT IF EXISTS order_lines_replacement_chk;
            ALTER TABLE order_lines DROP COLUMN IF EXISTS replaces_order_line_id;
            ALTER TABLE order_lines DROP CONSTRAINT order_lines_price_source_chk;
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_price_source_chk CHECK (price_source IN
              ('contract','customer','promotion','tier','base','manual'));
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_replacement_chk;
            ALTER TABLE orders DROP CONSTRAINT orders_payment_status_chk;
            ALTER TABLE orders ADD CONSTRAINT orders_payment_status_chk CHECK (payment_status IN
              ('unpaid','deposit_paid','paid','part_refunded','refunded','on_account'));
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_kind_chk;
            ALTER TABLE orders DROP COLUMN IF EXISTS order_kind;
        SQL);
    }
};
