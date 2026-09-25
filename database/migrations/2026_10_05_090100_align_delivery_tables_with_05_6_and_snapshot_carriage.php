<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §20 (signed off 2026-09-25).
 *
 * 1. delivery_zones / delivery_zone_postcodes brought into line with the
 *    module spec, 05.6 §4.2–4.3 — which, as 02 §8.5 already says of
 *    delivery_rates, wins over the minimal shape signed off before 05.6
 *    existed: zone flags and per-zone carriage-paid threshold; whole-area
 *    postcode rules (NULL districts) with a stored `specificity` so the
 *    narrowest matching rule wins through one index.
 * 2. orders snapshot their carriage (invariant 4): which rate and method,
 *    and the carriage VAT at its own tax class's rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE delivery_zones
              ADD COLUMN country_code                  char(2)  NOT NULL DEFAULT 'GB',
              ADD COLUMN is_mainland                   boolean  NOT NULL DEFAULT true,
              ADD COLUMN is_serviceable                boolean  NOT NULL DEFAULT true,
              ADD COLUMN requires_manual_quote         boolean  NOT NULL DEFAULT false,
              ADD COLUMN carriage_paid_threshold_minor bigint,
              ADD COLUMN transit_days                  smallint,
              ADD CONSTRAINT delivery_zones_threshold_chk
                CHECK (carriage_paid_threshold_minor IS NULL OR carriage_paid_threshold_minor >= 0);

            CREATE INDEX delivery_zones_country_idx ON delivery_zones (country_code)
              WHERE is_serviceable;

            ALTER TABLE delivery_zone_postcodes
              ALTER COLUMN district_from DROP NOT NULL,
              ALTER COLUMN district_to   DROP NOT NULL,
              DROP CONSTRAINT delivery_zone_postcodes_range_chk,
              DROP CONSTRAINT delivery_zone_postcodes_uq,
              ADD CONSTRAINT delivery_zone_postcodes_range_chk CHECK (
                  (district_from IS NULL AND district_to IS NULL)
               OR (district_from IS NOT NULL AND district_to IS NOT NULL AND district_to >= district_from)
              ),
              ADD CONSTRAINT delivery_zone_postcodes_uq
                UNIQUE NULLS NOT DISTINCT (area, district_from, district_to),
              ADD COLUMN specificity integer GENERATED ALWAYS AS (
                CASE WHEN district_from IS NULL THEN 9999 ELSE district_to - district_from END
              ) STORED;

            CREATE INDEX delivery_zone_postcodes_resolve_idx
              ON delivery_zone_postcodes (area, specificity)
              INCLUDE (district_from, district_to, delivery_zone_id);

            ALTER TABLE orders
              ADD COLUMN delivery_rate_id     bigint REFERENCES delivery_rates (id),
              ADD COLUMN delivery_method      text,
              ADD COLUMN shipping_tax_rate_bp integer,
              ADD COLUMN shipping_tax_minor   bigint NOT NULL DEFAULT 0,
              ADD CONSTRAINT orders_delivery_method_chk CHECK (delivery_method IS NULL OR delivery_method IN
                ('parcel','pallet','courier_next_day','collection')),
              ADD CONSTRAINT orders_shipping_tax_chk CHECK (shipping_tax_minor >= 0
                AND (shipping_tax_rate_bp IS NULL OR shipping_tax_rate_bp BETWEEN 0 AND 10000));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
              DROP CONSTRAINT IF EXISTS orders_shipping_tax_chk,
              DROP CONSTRAINT IF EXISTS orders_delivery_method_chk,
              DROP COLUMN IF EXISTS shipping_tax_minor,
              DROP COLUMN IF EXISTS shipping_tax_rate_bp,
              DROP COLUMN IF EXISTS delivery_method,
              DROP COLUMN IF EXISTS delivery_rate_id;

            DROP INDEX IF EXISTS delivery_zone_postcodes_resolve_idx;
            DELETE FROM delivery_zone_postcodes WHERE district_from IS NULL;
            ALTER TABLE delivery_zone_postcodes
              DROP COLUMN IF EXISTS specificity,
              DROP CONSTRAINT IF EXISTS delivery_zone_postcodes_uq,
              DROP CONSTRAINT IF EXISTS delivery_zone_postcodes_range_chk,
              ADD CONSTRAINT delivery_zone_postcodes_uq UNIQUE (area, district_from, district_to),
              ADD CONSTRAINT delivery_zone_postcodes_range_chk CHECK (district_to >= district_from),
              ALTER COLUMN district_from SET NOT NULL,
              ALTER COLUMN district_to   SET NOT NULL;

            DROP INDEX IF EXISTS delivery_zones_country_idx;
            ALTER TABLE delivery_zones
              DROP CONSTRAINT IF EXISTS delivery_zones_threshold_chk,
              DROP COLUMN IF EXISTS transit_days,
              DROP COLUMN IF EXISTS carriage_paid_threshold_minor,
              DROP COLUMN IF EXISTS requires_manual_quote,
              DROP COLUMN IF EXISTS is_serviceable,
              DROP COLUMN IF EXISTS is_mainland,
              DROP COLUMN IF EXISTS country_code;
        SQL);
    }
};
