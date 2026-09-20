<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.6 §5.2 (delivery-collection module spec) — delivery_rates.
 *
 * Rebuilt 2026-09-22 to match 05.6, which arrived in /docs after the 02
 * §8.5 amendment (2026-09-21) had already drafted this table from a
 * spend-threshold guess. The two designs were incompatible: 05.6 bands
 * by carrier weight (`weight_range int4range`) and `method`, adds a
 * `tax_class_id` (carriage is its own taxable supply, §5.2 note), and
 * has no `min_subtotal_minor`/`code`/`priority`/`currency` at all. 05.6
 * is the authoritative module spec, so it wins outright rather than
 * being reconciled with the guess. No data existed to migrate — the
 * table was never used outside this session's own tests.
 *
 * No nullable column sits in the exclusion list (zone_id, method and
 * weight_range are all NOT NULL), so unlike tax_rates_no_overlap and
 * order_spend_breaks_no_overlap this constraint needed no COALESCE —
 * confirmed during the 2026-09-22 audit alongside two module tables
 * that did (05.7 commodity_duty_rates, 05.8 rep_commission_rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE delivery_rates (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              zone_id            bigint      NOT NULL REFERENCES delivery_zones (id),
              method             text        NOT NULL DEFAULT 'parcel',
              weight_range       int4range   NOT NULL,
              price_net_minor    bigint      NOT NULL,
              per_extra_kg_minor bigint,
              tax_class_id       bigint      NOT NULL REFERENCES tax_classes (id),
              validity           tstzrange   NOT NULL DEFAULT tstzrange(now(), NULL, '[)'),
              status             text        NOT NULL DEFAULT 'draft',
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT delivery_rates_method_chk CHECK (method IN
                ('parcel','pallet','courier_next_day','collection')),
              CONSTRAINT delivery_rates_status_chk CHECK (status IN ('draft','active','archived')),
              CONSTRAINT delivery_rates_price_chk  CHECK (price_net_minor >= 0),
              CONSTRAINT delivery_rates_bands_chk  CHECK (NOT isempty(weight_range)),
              CONSTRAINT delivery_rates_validity_chk CHECK (NOT isempty(validity)),

              -- neither overlapping weight bands nor overlapping validity windows
              CONSTRAINT delivery_rates_no_overlap
                EXCLUDE USING gist (zone_id WITH =, method WITH =,
                                    weight_range WITH &&, validity WITH &&)
                WHERE (status = 'active')
            );

            CREATE INDEX delivery_rates_resolve_idx
              ON delivery_rates USING gist (zone_id, method, weight_range, validity)
              WHERE status = 'active';
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS delivery_rates CASCADE');
    }
};
