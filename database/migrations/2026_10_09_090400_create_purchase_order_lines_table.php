<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.7 §5 (DDL signed off 2026-09-25) — purchase_order_lines.
 * `purchase_order_lines_base_qty_chk` is the same keystone pack constraint
 * as on order and quote lines (02 §8.3). `variance_reason` is the closed
 * list goods-in records an over- or under-receipt against (05.5 §4.4).
 * `purchase_order_lines_receivable_idx` is covering and partial: the
 * goods-in screen's "what is still outstanding on this PO".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE purchase_order_lines (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              purchase_order_id   bigint   NOT NULL REFERENCES purchase_orders (id)
                                             ON DELETE CASCADE,
              line_no             smallint NOT NULL,
              sku_id              bigint   NOT NULL REFERENCES skus (id),
              pack_id             bigint   NOT NULL REFERENCES packs (id),
              sku_code_snapshot   text     NOT NULL,
              pack_qty            integer  NOT NULL,
              pack_base_units     integer  NOT NULL,
              base_qty            integer  NOT NULL,
              received_base_qty   integer  NOT NULL DEFAULT 0,
              unit_fob_e4         bigint   NOT NULL,
              line_fob_minor      bigint   NOT NULL,
              line_fob_base_minor bigint   NOT NULL,
              line_weight_g       bigint,
              line_volume_cm3     bigint,
              expected_at         date,
              variance_reason     text,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT purchase_order_lines_po_line_uq     UNIQUE (purchase_order_id, line_no),
              CONSTRAINT purchase_order_lines_po_sku_pack_uq
                UNIQUE (purchase_order_id, sku_id, pack_id),
              CONSTRAINT purchase_order_lines_base_qty_chk
                CHECK (base_qty = pack_qty * pack_base_units),
              CONSTRAINT purchase_order_lines_variance_chk CHECK (variance_reason IS NULL
                OR variance_reason IN ('short_shipped','over_shipped','damaged_in_transit',
                                       'supplier_substitution','miscount_corrected','other'))
            );

            CREATE INDEX purchase_order_lines_sku_idx
              ON purchase_order_lines (sku_id, purchase_order_id) INCLUDE (base_qty, received_base_qty);
            -- partial: the goods-in screen wants only what is still outstanding
            CREATE INDEX purchase_order_lines_receivable_idx
              ON purchase_order_lines (purchase_order_id)
              INCLUDE (sku_id, pack_id, base_qty, received_base_qty)
              WHERE received_base_qty < base_qty;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS purchase_order_lines CASCADE');
    }
};
