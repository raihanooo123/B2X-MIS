<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §7.4 — stock_movements, the append-only source of truth. No
 * UPDATE, no DELETE, ever — a mistake is corrected by a compensating
 * movement with a reason code.
 *
 * Three deliberate Postgres-specific decisions carried over exactly as
 * specified:
 *  1. Declarative range partitioning on occurred_at from day one, with a
 *     DEFAULT catch-all partition. The composite PRIMARY KEY (id,
 *     occurred_at) is required — Postgres demands the partition key in
 *     every unique constraint on a partitioned table.
 *  2. A BRIN index on occurred_at (pages_per_range = 32): the table is
 *     append-only and physically correlated by time, so BRIN is ~3
 *     orders of magnitude smaller than an equivalent B-tree here.
 *  3. NO foreign keys, by choice, not by limitation: the inventory
 *     service is the sole writer, and referential integrity is asserted
 *     by the nightly reconciliation job (§11.4) instead. This is a
 *     recorded write-cost trade-off, not an oversight — do not add FKs
 *     here without revisiting that trade-off first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_movements (
              id              bigint GENERATED ALWAYS AS IDENTITY,
              occurred_at     timestamptz NOT NULL DEFAULT now(),
              sku_id          bigint      NOT NULL,
              location_id     bigint      NOT NULL,
              batch_id        bigint,
              serial_id       bigint,
              bin_id          bigint,
              movement_type   text        NOT NULL,
              base_qty        integer     NOT NULL,
              balance_after   integer,
              reference_type  text,
              reference_id    bigint,
              unit_cost_e4    bigint,
              reason_code     text,
              note            text,
              actor_user_id   bigint,
              created_at      timestamptz NOT NULL DEFAULT now(),

              PRIMARY KEY (id, occurred_at),
              CONSTRAINT stock_movements_type_chk CHECK (movement_type IN
                ('goods_in','allocation','deallocation','dispatch','return_in',
                 'adjustment','stocktake','transfer_in','transfer_out','write_off')),
              CONSTRAINT stock_movements_reason_chk CHECK (
                movement_type NOT IN ('adjustment','stocktake','write_off')
                OR reason_code IS NOT NULL)
            ) PARTITION BY RANGE (occurred_at);

            CREATE TABLE stock_movements_2026 PARTITION OF stock_movements
              FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
            CREATE TABLE stock_movements_2027 PARTITION OF stock_movements
              FOR VALUES FROM ('2027-01-01') TO ('2028-01-01');
            CREATE TABLE stock_movements_default PARTITION OF stock_movements DEFAULT;

            CREATE INDEX stock_movements_sku_loc_batch_idx
              ON stock_movements (sku_id, location_id, batch_id, occurred_at);
            CREATE INDEX stock_movements_batch_idx ON stock_movements (batch_id, occurred_at)
              WHERE batch_id IS NOT NULL;
            CREATE INDEX stock_movements_serial_idx ON stock_movements (serial_id)
              WHERE serial_id IS NOT NULL;
            CREATE INDEX stock_movements_reference_idx
              ON stock_movements (reference_type, reference_id)
              WHERE reference_type IS NOT NULL;
            CREATE INDEX stock_movements_occurred_brin
              ON stock_movements USING brin (occurred_at) WITH (pages_per_range = 32);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_movements CASCADE');
    }
};
