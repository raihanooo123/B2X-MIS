<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.7 — order_spend_breaks. Order-wide spend thresholds applied
 * as a second pass after item-level pricing resolves. Semantics: exactly
 * one break applies, never stacked; precedence company > tier > global,
 * then priority DESC, then id DESC.
 *
 * Doc 02 §6.7 correction (2026-09-18): the exclusion list uses
 * `COALESCE(price_tier_id, 0)` / `COALESCE(company_id, 0)`, not bare
 * columns. Verified live that the original form never enforces the
 * "one break per audience per threshold per window" invariant on any
 * scope — order_spend_breaks_coherence_chk guarantees at least one of
 * these columns is NULL on every row, so `NULL = NULL` never matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE order_spend_breaks (
              id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code                      text        NOT NULL,
              name                      text        NOT NULL,
              scope                     text        NOT NULL DEFAULT 'global',
              price_tier_id             bigint      REFERENCES price_tiers (id),
              company_id                bigint      REFERENCES companies (id),
              min_subtotal_minor        bigint      NOT NULL,
              discount_type             text        NOT NULL DEFAULT 'percentage',
              discount_rate_bp          integer,
              discount_amount_minor     bigint,
              max_discount_minor        bigint,
              currency                  char(3)     NOT NULL DEFAULT 'GBP',
              applies_to_contract_lines boolean     NOT NULL DEFAULT false,
              priority                  smallint    NOT NULL DEFAULT 100,
              validity                  tstzrange   NOT NULL DEFAULT tstzrange(now(), NULL, '[)'),
              status                    text        NOT NULL DEFAULT 'draft',
              created_at                timestamptz NOT NULL DEFAULT now(),
              updated_at                timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT order_spend_breaks_code_uq UNIQUE (code),
              CONSTRAINT order_spend_breaks_scope_chk
                CHECK (scope IN ('global','tier','company')),
              CONSTRAINT order_spend_breaks_status_chk
                CHECK (status IN ('draft','active','archived')),
              CONSTRAINT order_spend_breaks_subtotal_chk CHECK (min_subtotal_minor > 0),
              CONSTRAINT order_spend_breaks_validity_chk CHECK (NOT isempty(validity)),
              CONSTRAINT order_spend_breaks_coherence_chk CHECK (
                  (scope = 'global'  AND price_tier_id IS NULL AND company_id IS NULL)
               OR (scope = 'tier'    AND price_tier_id IS NOT NULL AND company_id IS NULL)
               OR (scope = 'company' AND company_id IS NOT NULL)
              ),
              CONSTRAINT order_spend_breaks_discount_chk CHECK (
                  (discount_type = 'percentage' AND discount_rate_bp IS NOT NULL
                                                AND discount_rate_bp BETWEEN 1 AND 10000
                                                AND discount_amount_minor IS NULL)
               OR (discount_type = 'fixed'      AND discount_amount_minor IS NOT NULL
                                                AND discount_amount_minor > 0
                                                AND discount_rate_bp IS NULL)
              ),

              -- one break per audience per threshold per window
              CONSTRAINT order_spend_breaks_no_overlap
                EXCLUDE USING gist (scope WITH =, COALESCE(price_tier_id, 0) WITH =,
                                    COALESCE(company_id, 0) WITH =,
                                    min_subtotal_minor WITH =, validity WITH &&)
                WHERE (status = 'active')
            );

            CREATE INDEX order_spend_breaks_resolve_idx
              ON order_spend_breaks (scope, min_subtotal_minor DESC)
              INCLUDE (discount_type, discount_rate_bp, discount_amount_minor,
                       max_discount_minor, price_tier_id, company_id, priority)
              WHERE status = 'active';
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS order_spend_breaks CASCADE');
    }
};
