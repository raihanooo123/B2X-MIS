<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.6 — sku_costs. Landed cost for real margin. `landed_cost_e4`
 * is a stored generated column, indexable and unable to drift from its
 * components.
 *
 * Not a validity-window table: unlike price_lists/tax_rates/
 * order_spend_breaks, cost history here is a plain point-in-time log
 * (`valid_from`, no `valid_to`/`tstzrange`), resolved by
 * `ORDER BY valid_from DESC LIMIT 1` via sku_costs_current_idx — the doc
 * has no EXCLUDE/overlap constraint on this table and none is added here.
 *
 * `purchase_order_id` and `container_id` are plain bigint with no
 * REFERENCES clause in the doc itself (purchase_orders/containers are
 * Phase 3) — not a gap, that's how the doc specifies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE sku_costs (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              sku_id            bigint      NOT NULL REFERENCES skus (id) ON DELETE CASCADE,
              source            text        NOT NULL DEFAULT 'manual',
              purchase_order_id bigint,
              container_id      bigint,
              currency          char(3)     NOT NULL DEFAULT 'GBP',
              fx_rate_e4        bigint,
              fob_e4            bigint      NOT NULL DEFAULT 0,
              freight_e4        bigint      NOT NULL DEFAULT 0,
              duty_e4           bigint      NOT NULL DEFAULT 0,
              other_e4          bigint      NOT NULL DEFAULT 0,
              landed_cost_e4    bigint GENERATED ALWAYS AS
                                  (fob_e4 + freight_e4 + duty_e4 + other_e4) STORED,
              is_provisional    boolean     NOT NULL DEFAULT false,
              valid_from        timestamptz NOT NULL,
              created_at        timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT sku_costs_source_chk CHECK (source IN
                ('manual','purchase_order','container_allocation')),
              CONSTRAINT sku_costs_fx_chk CHECK (currency = 'GBP' OR fx_rate_e4 IS NOT NULL)
            );

            CREATE INDEX sku_costs_current_idx ON sku_costs (sku_id, valid_from DESC)
              INCLUDE (landed_cost_e4, is_provisional);
            CREATE INDEX sku_costs_po_idx ON sku_costs (purchase_order_id)
              WHERE purchase_order_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sku_costs CASCADE');
    }
};
