<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05.10 §2.4 (signed off 2026-10-05, 02 §30) — cancelling part of an order
 * before dispatch.
 *
 * `order_lines.cancelled_base_qty`, in whole packs, never more than what
 * is not dispatched; the placed line (quantity and money) never changes,
 * so the order reprints identically (invariant 4). Every cancellation,
 * part or whole, is an immutable `order_cancellations` record with its
 * lines. The picking screen's partial index leaves cancelled quantity
 * out; the old index is dropped by the next migration.
 *
 * Not in a transaction: CREATE INDEX CONCURRENTLY cannot run in one. Each
 * statement is its own, and every VALIDATE succeeds because existing lines
 * have `cancelled_base_qty = 0`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE order_lines ADD COLUMN cancelled_base_qty integer NOT NULL DEFAULT 0');
        DB::statement(<<<'SQL'
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_cancel_chk CHECK (
              cancelled_base_qty >= 0
              AND cancelled_base_qty % pack_base_units = 0
              AND dispatched_base_qty + cancelled_base_qty <= base_qty) NOT VALID
        SQL);
        DB::statement('ALTER TABLE order_lines VALIDATE CONSTRAINT order_lines_cancel_chk');

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS order_lines_outstanding_v2_idx ON order_lines (order_id)
              INCLUDE (sku_id, base_qty, dispatched_base_qty, cancelled_base_qty)
              WHERE dispatched_base_qty + cancelled_base_qty < base_qty
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE order_cancellations (
              id                          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id                   text        NOT NULL,
              order_id                    bigint      NOT NULL REFERENCES orders (id),
              kind                        text        NOT NULL,
              initiated_by                text        NOT NULL,
              actor_user_id               bigint      REFERENCES users (id),
              customer_notified_at        timestamptz NOT NULL,
              reason_code                 text,
              reason_detail               text,
              cancelled_net_minor         bigint      NOT NULL,
              cancelled_tax_minor         bigint      NOT NULL,
              cancelled_gross_minor       bigint      NOT NULL,
              delivery_refund_net_minor   bigint      NOT NULL DEFAULT 0,
              delivery_refund_tax_minor   bigint      NOT NULL DEFAULT 0,
              credit_hold_reduction_minor bigint      NOT NULL DEFAULT 0,
              credit_note_id              bigint      REFERENCES credit_notes (id),
              refund_payment_id           bigint      REFERENCES payments (id),
              client_token                text,
              created_at                  timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT order_cancellations_public_id_uq UNIQUE (public_id),
              CONSTRAINT order_cancellations_kind_chk CHECK (kind IN ('partial','whole')),
              CONSTRAINT order_cancellations_initiated_chk CHECK (initiated_by IN ('customer','staff','system')),
              CONSTRAINT order_cancellations_actor_chk CHECK (initiated_by <> 'staff' OR actor_user_id IS NOT NULL),
              -- Q-X1: only staff may cancel below a break quantity, and always with a reason
              CONSTRAINT order_cancellations_override_chk CHECK (reason_code IS DISTINCT FROM 'below_break_override'
                OR (initiated_by = 'staff' AND reason_detail IS NOT NULL AND btrim(reason_detail) <> '')),
              CONSTRAINT order_cancellations_sum_chk CHECK (
                cancelled_gross_minor = cancelled_net_minor + cancelled_tax_minor
                AND cancelled_net_minor >= 0 AND cancelled_tax_minor >= 0
                AND delivery_refund_net_minor >= 0 AND delivery_refund_tax_minor >= 0
                AND credit_hold_reduction_minor >= 0)
            );

            CREATE INDEX order_cancellations_order_idx ON order_cancellations (order_id, created_at);
            CREATE UNIQUE INDEX order_cancellations_token_uq ON order_cancellations (order_id, client_token)
              WHERE client_token IS NOT NULL;

            CREATE TABLE order_cancellation_lines (
              id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              order_cancellation_id  bigint  NOT NULL REFERENCES order_cancellations (id),
              order_line_id          bigint  NOT NULL REFERENCES order_lines (id),
              cancelled_pack_qty     integer NOT NULL,
              cancelled_base_qty     integer NOT NULL,
              line_net_minor         bigint  NOT NULL,
              line_tax_minor         bigint  NOT NULL,
              line_gross_minor       bigint  NOT NULL,

              CONSTRAINT order_cancellation_lines_uq UNIQUE (order_cancellation_id, order_line_id),
              CONSTRAINT order_cancellation_lines_qty_chk CHECK (cancelled_pack_qty > 0 AND cancelled_base_qty > 0),
              CONSTRAINT order_cancellation_lines_money_chk CHECK (
                line_gross_minor = line_net_minor + line_tax_minor
                AND line_net_minor >= 0 AND line_tax_minor >= 0)
            );

            CREATE INDEX order_cancellation_lines_line_idx ON order_cancellation_lines (order_line_id);

            -- reject_row_mutation() exists (02 §25.1): a cancellation is a record, never edited
            CREATE TRIGGER order_cancellations_immutable BEFORE UPDATE OR DELETE ON order_cancellations
              FOR EACH ROW EXECUTE FUNCTION reject_row_mutation();
            CREATE TRIGGER order_cancellation_lines_immutable BEFORE UPDATE OR DELETE ON order_cancellation_lines
              FOR EACH ROW EXECUTE FUNCTION reject_row_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS order_cancellation_lines;
            DROP TABLE IF EXISTS order_cancellations;
        SQL);
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS order_lines_outstanding_v2_idx');
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT IF EXISTS order_lines_cancel_chk');
        DB::statement('ALTER TABLE order_lines DROP COLUMN IF EXISTS cancelled_base_qty');
    }
};
