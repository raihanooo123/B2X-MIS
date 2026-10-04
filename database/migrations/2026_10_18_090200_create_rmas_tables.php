<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.4 §5.1–5.2 (RMAs and their lines), as amended for consumers on
 * 2026-10-04 (05.4 §13; 05.15 §9 A6, A7, signed off). `company_id` NULL is
 * a consumer order's return; `rmas_consumer_fee_chk` makes a fee on one
 * impossible to persist.
 *
 * Also: `credit_notes.rma_id` becomes a real foreign key now that `rmas`
 * exists (02 §14.5.3), and the `rma_number` and `credit_note_number`
 * series are created (02 §11.3), so neither depends on the demo seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE rmas (
              id                       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id                text        NOT NULL,
              rma_number               text        NOT NULL,
              company_id               bigint      REFERENCES companies (id),
              order_id                 bigint      NOT NULL REFERENCES orders (id),
              requested_by_user_id     bigint      REFERENCES users (id),
              handled_by_user_id       bigint      REFERENCES users (id),
              status                   text        NOT NULL DEFAULT 'requested',
              return_reason            text        NOT NULL,
              reason_detail            text,
              resolution_type          text,
              return_method            text,
              carriage_payer           text        NOT NULL DEFAULT 'customer',

              goods_net_minor          bigint      NOT NULL DEFAULT 0,
              restocking_rate_bp       smallint    NOT NULL,
              restocking_minimum_minor bigint      NOT NULL,
              restocking_fee_minor     bigint      NOT NULL DEFAULT 0,
              fee_waived               boolean     NOT NULL DEFAULT false,
              fee_waiver_reason        text,
              fee_waived_by_user_id    bigint      REFERENCES users (id),
              carriage_recharge_minor  bigint      NOT NULL DEFAULT 0,
              refund_net_minor         bigint      NOT NULL DEFAULT 0,
              refund_tax_minor         bigint      NOT NULL DEFAULT 0,
              refund_gross_minor       bigint      NOT NULL DEFAULT 0,

              credit_note_id           bigint      REFERENCES credit_notes (id),
              replacement_order_id     bigint      REFERENCES orders (id),

              cancellation_notified_at  timestamptz,
              possession_on             date,
              possession_basis          text,
              goods_sent_at             timestamptz,
              refund_due_on             date,
              delivery_refund_net_minor bigint     NOT NULL DEFAULT 0,
              delivery_refund_tax_minor bigint     NOT NULL DEFAULT 0,
              refund_payment_id         bigint     REFERENCES payments (id),

              requested_at             timestamptz NOT NULL DEFAULT now(),
              approved_at              timestamptz,
              return_by_date           date,
              received_at              timestamptz,
              inspected_at             timestamptz,
              resolved_at              timestamptz,
              internal_note            text,
              created_at               timestamptz NOT NULL DEFAULT now(),
              updated_at               timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT rmas_number_uq    UNIQUE (rma_number),
              CONSTRAINT rmas_public_id_uq UNIQUE (public_id),
              CONSTRAINT rmas_status_chk CHECK (status IN
                ('requested','approved','rejected','awaiting_goods','received','inspected',
                 'resolved','partially_resolved','not_received','cancelled')),
              CONSTRAINT rmas_reason_chk CHECK (return_reason IN
                ('damaged','faulty','wrong_item','wrong_quantity','not_as_described',
                 'over_ordered','no_longer_required','expired_on_arrival',
                 'shipping_damage','other','consumer_cancellation')),
              CONSTRAINT rmas_resolution_chk CHECK (resolution_type IS NULL
                OR resolution_type IN ('credit_note','replacement','repair','none')),
              CONSTRAINT rmas_method_chk CHECK (return_method IS NULL
                OR return_method IN ('customer_carriage','collection','courier_label')),
              CONSTRAINT rmas_payer_chk CHECK (carriage_payer IN ('customer','us')),
              CONSTRAINT rmas_rate_chk  CHECK (restocking_rate_bp <= 10000),
              CONSTRAINT rmas_waiver_chk CHECK (NOT fee_waived
                OR (fee_waiver_reason IS NOT NULL AND fee_waived_by_user_id IS NOT NULL)),
              CONSTRAINT rmas_consumer_fee_chk CHECK (company_id IS NOT NULL OR (
                restocking_rate_bp = 0 AND restocking_minimum_minor = 0 AND restocking_fee_minor = 0
                AND NOT fee_waived AND carriage_recharge_minor = 0)),
              CONSTRAINT rmas_cancellation_chk CHECK (return_reason <> 'consumer_cancellation' OR (
                company_id IS NULL AND cancellation_notified_at IS NOT NULL
                AND possession_on IS NOT NULL AND possession_basis IS NOT NULL)),
              CONSTRAINT rmas_possession_basis_chk CHECK (possession_basis IS NULL
                OR possession_basis IN ('carrier_delivered','estimated_from_dispatch','collected')),
              CONSTRAINT rmas_delivery_refund_chk CHECK (
                delivery_refund_net_minor >= 0 AND delivery_refund_tax_minor >= 0
                AND (company_id IS NULL OR (delivery_refund_net_minor = 0 AND delivery_refund_tax_minor = 0)))
            );

            CREATE INDEX rmas_company_created_idx ON rmas (company_id, requested_at DESC, id);
            CREATE INDEX rmas_queue_idx ON rmas (requested_at)
              WHERE status IN ('requested','approved','awaiting_goods','received','inspected');
            CREATE INDEX rmas_return_by_idx ON rmas (return_by_date)
              WHERE status = 'awaiting_goods';
            CREATE INDEX rmas_order_idx  ON rmas (order_id);
            CREATE INDEX rmas_reason_idx ON rmas (return_reason, requested_at);
            CREATE INDEX rmas_refund_due_idx ON rmas (refund_due_on)
              WHERE refund_due_on IS NOT NULL
                AND status IN ('approved','awaiting_goods','received','inspected');

            CREATE TABLE rma_lines (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              rma_id                bigint   NOT NULL REFERENCES rmas (id) ON DELETE CASCADE,
              line_no               smallint NOT NULL,
              order_line_id         bigint   NOT NULL REFERENCES order_lines (id),
              sku_id                bigint   NOT NULL REFERENCES skus (id),
              pack_id               bigint   NOT NULL REFERENCES packs (id),

              sku_code_snapshot     text     NOT NULL,
              name_snapshot         text     NOT NULL,

              requested_pack_qty    integer  NOT NULL,
              requested_base_qty    integer  NOT NULL,
              received_base_qty     integer  NOT NULL DEFAULT 0,
              restocked_base_qty    integer  NOT NULL DEFAULT 0,
              quarantined_base_qty  integer  NOT NULL DEFAULT 0,
              written_off_base_qty  integer  NOT NULL DEFAULT 0,

              unit_price_net_e4     bigint   NOT NULL,
              tax_rate_bp           smallint NOT NULL,
              line_goods_net_minor  bigint   NOT NULL DEFAULT 0,
              line_fee_minor        bigint   NOT NULL DEFAULT 0,
              line_refund_net_minor bigint   NOT NULL DEFAULT 0,
              line_refund_tax_minor bigint   NOT NULL DEFAULT 0,
              diminished_value_minor  bigint NOT NULL DEFAULT 0,
              diminished_value_reason text,

              batch_id              bigint   REFERENCES batches (id),
              disposition           text     NOT NULL DEFAULT 'pending',
              disposition_reason    text,
              inspected_by_user_id  bigint   REFERENCES users (id),
              inspected_at          timestamptz,

              created_at            timestamptz NOT NULL DEFAULT now(),
              updated_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT rma_lines_rma_line_uq       UNIQUE (rma_id, line_no),
              CONSTRAINT rma_lines_rma_order_line_uq UNIQUE (rma_id, order_line_id),
              CONSTRAINT rma_lines_disposition_chk CHECK (disposition IN
                ('pending','restock','quarantine','write_off','return_to_customer')),
              CONSTRAINT rma_lines_received_chk
                CHECK (received_base_qty <= requested_base_qty * 2),
              CONSTRAINT rma_lines_disposed_chk
                CHECK (restocked_base_qty + quarantined_base_qty + written_off_base_qty
                       <= received_base_qty),
              CONSTRAINT rma_lines_restock_batch_chk
                CHECK (disposition <> 'restock' OR restocked_base_qty = 0 OR batch_id IS NOT NULL
                       OR quarantined_base_qty >= 0),
              CONSTRAINT rma_lines_diminished_chk CHECK (diminished_value_minor >= 0
                AND (diminished_value_minor = 0 OR diminished_value_reason IS NOT NULL))
            );

            CREATE INDEX rma_lines_order_line_idx ON rma_lines (order_line_id);
            CREATE INDEX rma_lines_sku_idx        ON rma_lines (sku_id, rma_id);
            CREATE INDEX rma_lines_batch_idx      ON rma_lines (batch_id)
              WHERE batch_id IS NOT NULL;
            CREATE INDEX rma_lines_disposition_idx ON rma_lines (rma_id)
              WHERE disposition = 'pending';

            ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_rma_fk
              FOREIGN KEY (rma_id) REFERENCES rmas (id);

            INSERT INTO number_sequences (key_name, prefix, next_value, padding)
            VALUES ('rma_number', 'RMA-', 1, 6), ('credit_note_number', 'CN-', 1, 6)
            ON CONFLICT (key_name) DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE credit_notes DROP CONSTRAINT IF EXISTS credit_notes_rma_fk;
            DROP TABLE IF EXISTS rma_lines;
            DROP TABLE IF EXISTS rmas;
            DELETE FROM number_sequences WHERE key_name = 'rma_number';
        SQL);
    }
};
