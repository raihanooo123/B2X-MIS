<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.3 — order_lines. Where pack and price snapshots live; the
 * order must render identically in ten years.
 *
 * `sku_cost_id` is intentionally NOT a foreign key yet: `sku_costs`
 * (§6.6) has full DDL in Doc 02 but has not been scaffolded in any
 * session's scope yet. `price_list_item_id` is a plain bigint with no
 * REFERENCES clause in the doc itself — not a gap, that's how it's
 * specified.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE order_lines (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              order_id              bigint      NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
              line_no               smallint    NOT NULL,
              sku_id                bigint      NOT NULL REFERENCES skus (id),
              pack_id               bigint      NOT NULL REFERENCES packs (id),

              -- immutable snapshots: the order must render identically in ten years
              sku_code_snapshot     text        NOT NULL,
              name_snapshot         text        NOT NULL,
              pack_label_snapshot   text        NOT NULL,

              -- pack arithmetic, all three persisted
              pack_qty              integer     NOT NULL,
              pack_base_units       integer     NOT NULL,
              base_qty              integer     NOT NULL,

              -- price snapshot, per base unit (e4 scale, §2.2)
              unit_price_net_e4     bigint      NOT NULL,
              line_discount_minor   bigint      NOT NULL DEFAULT 0,
              line_spend_discount_minor bigint  NOT NULL DEFAULT 0,
              line_net_minor        bigint      NOT NULL,
              tax_rate_bp           smallint    NOT NULL,
              line_tax_minor        bigint      NOT NULL,
              line_gross_minor      bigint      NOT NULL,

              -- provenance: which rule produced this price
              price_source          text        NOT NULL DEFAULT 'base',
              price_list_id         bigint      REFERENCES price_lists (id),
              price_list_item_id    bigint,
              applied_break_qty     integer,

              -- cost snapshot for margin (e4 scale)
              unit_cost_e4          bigint,
              sku_cost_id           bigint,

              -- fulfilment progress
              allocated_base_qty    integer     NOT NULL DEFAULT 0,
              dispatched_base_qty   integer     NOT NULL DEFAULT 0,
              returned_base_qty     integer     NOT NULL DEFAULT 0,

              created_at            timestamptz NOT NULL DEFAULT now(),
              updated_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT order_lines_order_line_uq     UNIQUE (order_id, line_no),
              CONSTRAINT order_lines_order_sku_pack_uq UNIQUE (order_id, sku_id, pack_id),
              CONSTRAINT order_lines_price_source_chk CHECK (price_source IN
                ('contract','customer','promotion','tier','base','manual')),

              -- pack integrity: base_qty can never disagree with the pack arithmetic
              CONSTRAINT order_lines_base_qty_chk CHECK (base_qty = pack_qty * pack_base_units),
              CONSTRAINT order_lines_dispatch_chk CHECK (dispatched_base_qty <= base_qty),
              CONSTRAINT order_lines_return_chk   CHECK (returned_base_qty <= dispatched_base_qty),
              CONSTRAINT order_lines_qty_pos_chk  CHECK (pack_qty > 0 AND pack_base_units > 0),
              CONSTRAINT order_lines_tax_chk      CHECK (tax_rate_bp BETWEEN 0 AND 10000)
            );

            COMMENT ON COLUMN order_lines.sku_cost_id IS
              'FK to sku_costs(id) pending — sku_costs (Doc 02 §6.6) is fully specified but not yet scaffolded in any session.';

            CREATE INDEX order_lines_sku_idx   ON order_lines (sku_id, order_id)
              INCLUDE (base_qty, line_net_minor);
            CREATE INDEX order_lines_order_idx ON order_lines (order_id, sku_id);
            CREATE INDEX order_lines_outstanding_idx ON order_lines (order_id)
              INCLUDE (sku_id, base_qty, dispatched_base_qty)
              WHERE dispatched_base_qty < base_qty;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS order_lines CASCADE');
    }
};
