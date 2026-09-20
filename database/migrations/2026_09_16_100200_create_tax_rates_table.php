<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.5 — tax_rates. Dated, non-overlapping VAT rates per tax class,
 * country and region. `EXCLUDE USING gist` (needs btree_gist, enabled in
 * the extensions migration) makes two overlapping rates for the same
 * (tax_class, country, region) unrepresentable — an HMRC problem, not
 * merely a commercial one.
 *
 * Doc 02 §6.5 correction (2026-09-16): the exclusion list uses
 * `COALESCE(region, '') WITH =`, not a bare `region WITH =`. Verified
 * live that the original form lets two overlapping NULL-region rates
 * (the common, national-rate case — every seed value included) insert
 * without conflict, since `NULL = NULL` is never true. `EXCLUDE` has no
 * `NULLS NOT DISTINCT` option in Postgres 16 (unlike `UNIQUE`); COALESCE
 * is the equivalent fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE tax_rates (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              tax_class_id bigint    NOT NULL REFERENCES tax_classes (id),
              country_code char(2)   NOT NULL DEFAULT 'GB',
              region       text,
              rate_bp      smallint  NOT NULL,
              validity     tstzrange NOT NULL DEFAULT tstzrange(now(), NULL, '[)'),

              CONSTRAINT tax_rates_rate_chk CHECK (rate_bp BETWEEN 0 AND 10000),
              CONSTRAINT tax_rates_validity_chk CHECK (NOT isempty(validity)),
              CONSTRAINT tax_rates_no_overlap
                EXCLUDE USING gist (tax_class_id WITH =, country_code WITH =,
                                    COALESCE(region, '') WITH =, validity WITH &&)
            );

            CREATE INDEX tax_rates_resolve_idx
              ON tax_rates (tax_class_id, country_code) INCLUDE (rate_bp, region, validity);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS tax_rates CASCADE');
    }
};
