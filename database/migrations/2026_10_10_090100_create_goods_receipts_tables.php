<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §23 (signed off 2026-09-25) — goods_receipts and
 * goods_receipt_lines.
 *
 * `goods_receipt_lines_idempotency_uq` is the durable half of 05.5 §10's
 * receipt idempotency — `(receipt_id, po_line_id, client_token)` — with
 * `NULLS NOT DISTINCT` because a manual line has no PO line. Lines are
 * append-only: no `updated_at`; a confirmed line is corrected by an
 * `adjustment` movement, never edited.
 *
 * Also seeds the `po_number` series (02 §11.3): purchase_orders exists as
 * of 2026_10_09_090300, and NumberSequenceService never starts an unknown
 * series itself. Prefix and padding match the other document series.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE goods_receipts (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id          text        NOT NULL,
              source             text        NOT NULL,
              purchase_order_id  bigint      REFERENCES purchase_orders (id),
              container_id       bigint      REFERENCES containers (id),
              location_id        bigint      NOT NULL REFERENCES locations (id),
              status             text        NOT NULL DEFAULT 'open',
              opened_by_user_id  bigint      REFERENCES users (id),
              closed_by_user_id  bigint      REFERENCES users (id),
              opened_at          timestamptz NOT NULL DEFAULT now(),
              closed_at          timestamptz,
              note               text,
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT goods_receipts_public_id_uq UNIQUE (public_id),
              CONSTRAINT goods_receipts_source_chk CHECK (source IN
                ('purchase_order','container','manual')),
              CONSTRAINT goods_receipts_source_ref_chk CHECK (
                   (source = 'purchase_order' AND purchase_order_id IS NOT NULL AND container_id IS NULL)
                OR (source = 'container'      AND container_id IS NOT NULL      AND purchase_order_id IS NULL)
                OR (source = 'manual'         AND purchase_order_id IS NULL     AND container_id IS NULL)),
              CONSTRAINT goods_receipts_status_chk CHECK (status IN ('open','closed')),
              CONSTRAINT goods_receipts_closed_chk CHECK ((status = 'closed') = (closed_at IS NOT NULL))
            );

            CREATE INDEX goods_receipts_open_idx ON goods_receipts (location_id, opened_at)
              WHERE status = 'open';
            CREATE INDEX goods_receipts_po_idx ON goods_receipts (purchase_order_id)
              WHERE purchase_order_id IS NOT NULL;
            CREATE INDEX goods_receipts_container_idx ON goods_receipts (container_id)
              WHERE container_id IS NOT NULL;
            CREATE INDEX goods_receipts_manual_idx ON goods_receipts (opened_at)
              WHERE source = 'manual';

            CREATE TABLE goods_receipt_lines (
              id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              goods_receipt_id       bigint      NOT NULL REFERENCES goods_receipts (id),
              purchase_order_line_id bigint      REFERENCES purchase_order_lines (id),
              client_token           text        NOT NULL,
              sku_id                 bigint      NOT NULL REFERENCES skus (id),
              pack_id                bigint      NOT NULL REFERENCES packs (id),
              pack_qty               integer     NOT NULL,
              pack_base_units        integer     NOT NULL,
              base_qty               integer     NOT NULL,
              batch_id               bigint      REFERENCES batches (id),
              bin_id                 bigint      REFERENCES bins (id),
              sku_cost_id            bigint      REFERENCES sku_costs (id),
              received_by_user_id    bigint      REFERENCES users (id),
              received_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT goods_receipt_lines_idempotency_uq
                UNIQUE NULLS NOT DISTINCT (goods_receipt_id, purchase_order_line_id, client_token),
              CONSTRAINT goods_receipt_lines_base_qty_chk CHECK (base_qty = pack_qty * pack_base_units),
              CONSTRAINT goods_receipt_lines_qty_chk CHECK (pack_qty > 0)
            );

            CREATE INDEX goods_receipt_lines_po_line_idx ON goods_receipt_lines (purchase_order_line_id)
              INCLUDE (base_qty) WHERE purchase_order_line_id IS NOT NULL;
            CREATE INDEX goods_receipt_lines_batch_idx ON goods_receipt_lines (batch_id)
              WHERE batch_id IS NOT NULL;
            CREATE INDEX goods_receipt_lines_uncosted_idx ON goods_receipt_lines (received_at)
              WHERE sku_cost_id IS NULL;

            INSERT INTO number_sequences (key_name, prefix, next_value, padding)
            VALUES ('po_number', 'PO-', 1, 6)
            ON CONFLICT (key_name) DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM number_sequences WHERE key_name = 'po_number';
            DROP TABLE IF EXISTS goods_receipt_lines CASCADE;
            DROP TABLE IF EXISTS goods_receipts CASCADE;
        SQL);
    }
};
