<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.7 §6 (DDL signed off 2026-09-25) — containers. One container
 * carries goods from several POs; its freight and duty are apportioned
 * across them (§8). `container_costs` and `container_cost_allocations`
 * (§6–7) are not migrated here — they arrive with landed-cost
 * apportionment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE containers (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              container_ref       text        NOT NULL,
              bill_of_lading      text,
              vessel_name         text,
              container_type      text        NOT NULL DEFAULT '40ft',
              origin_port         text,
              destination_port    text,
              location_id         bigint      NOT NULL REFERENCES locations (id),
              status              text        NOT NULL DEFAULT 'planned',
              etd_date            date,
              eta_date            date,
              arrived_at          timestamptz,
              cleared_at          timestamptz,
              apportionment_basis text        NOT NULL DEFAULT 'fob_value',
              costs_finalised_at  timestamptz,
              note                text,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT containers_ref_uq UNIQUE (container_ref),
              CONSTRAINT containers_type_chk CHECK (container_type IN
                ('20ft','40ft','40ft_hc','lcl','air','road')),
              CONSTRAINT containers_status_chk CHECK (status IN
                ('planned','booked','in_transit','at_port','customs_cleared',
                 'delivered','received','closed')),
              CONSTRAINT containers_basis_chk CHECK (apportionment_basis IN
                ('fob_value','weight','volume','units'))
            );

            -- partial: only containers still in flight appear on the arrivals board
            CREATE INDEX containers_open_eta_idx ON containers (eta_date)
              WHERE status IN ('planned','booked','in_transit','at_port','customs_cleared');
            CREATE INDEX containers_unfinalised_idx ON containers (arrived_at)
              WHERE costs_finalised_at IS NULL AND status IN ('received','delivered');
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS containers CASCADE');
    }
};
