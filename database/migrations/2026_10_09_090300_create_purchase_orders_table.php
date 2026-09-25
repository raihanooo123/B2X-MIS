<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.7 §5 (DDL signed off 2026-09-25) — purchase_orders.
 * `goods_total_minor` is in the PO currency, `goods_total_base_minor` the
 * GBP equivalent — both stored so the FX rate used is never ambiguous.
 * The open-status indexes are partial: the purchasing dashboard and the
 * incoming-stock projection see open POs only.
 *
 * `po_number` is gapless from `number_sequences` (02 §11.3, CLAUDE.md
 * invariant 8) — seed a 'po_number' key_name row before raising POs; not
 * done by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE purchase_orders (
              id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id              text        NOT NULL,
              po_number              text        NOT NULL,
              supplier_id            bigint      NOT NULL REFERENCES suppliers (id),
              container_id           bigint      REFERENCES containers (id),
              location_id            bigint      NOT NULL REFERENCES locations (id),
              status                 text        NOT NULL DEFAULT 'draft',
              incoterm               text        NOT NULL DEFAULT 'FOB',
              currency               char(3)     NOT NULL DEFAULT 'GBP',
              fx_rate_e4             bigint,
              goods_total_minor      bigint      NOT NULL DEFAULT 0,
              goods_total_base_minor bigint      NOT NULL DEFAULT 0,
              supplier_reference     text,
              ordered_at             timestamptz,
              expected_at            date,
              received_at            timestamptz,
              raised_by_user_id      bigint      REFERENCES users (id),
              note                   text,
              created_at             timestamptz NOT NULL DEFAULT now(),
              updated_at             timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT purchase_orders_number_uq    UNIQUE (po_number),
              CONSTRAINT purchase_orders_public_id_uq UNIQUE (public_id),
              CONSTRAINT purchase_orders_status_chk CHECK (status IN
                ('draft','sent','confirmed','in_production','shipped',
                 'part_received','received','closed','cancelled')),
              CONSTRAINT purchase_orders_incoterm_chk CHECK (incoterm IN
                ('EXW','FOB','CIF','CFR','DAP','DDP')),
              CONSTRAINT purchase_orders_fx_chk CHECK (currency = 'GBP' OR fx_rate_e4 IS NOT NULL)
            );

            CREATE INDEX purchase_orders_supplier_status_idx
              ON purchase_orders (supplier_id, expected_at)
              WHERE status IN ('confirmed','in_production','shipped','part_received');
            CREATE INDEX purchase_orders_open_expected_idx ON purchase_orders (expected_at)
              WHERE status IN ('confirmed','in_production','shipped','part_received');
            CREATE INDEX purchase_orders_container_idx ON purchase_orders (container_id)
              WHERE container_id IS NOT NULL;
            CREATE INDEX purchase_orders_location_idx ON purchase_orders (location_id, status);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS purchase_orders CASCADE');
    }
};
