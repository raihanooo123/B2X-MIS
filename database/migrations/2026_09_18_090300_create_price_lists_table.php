<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §6.3 — price_lists. Three EXCLUDE constraints, one per scope,
 * make overlapping active pricing windows for the same audience
 * unrepresentable — the decision the doc calls out as most justifying
 * PostgreSQL over the MySQL edition's deterministic-tiebreak workaround.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE price_lists (
              id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code          text        NOT NULL,
              name          text        NOT NULL,
              scope         text        NOT NULL,
              price_tier_id bigint      REFERENCES price_tiers (id),
              company_id    bigint      REFERENCES companies (id),
              promotion_id  bigint,
              currency      char(3)     NOT NULL DEFAULT 'GBP',
              has_contract  boolean     NOT NULL DEFAULT false,
              priority      smallint    NOT NULL DEFAULT 100,
              validity      tstzrange   NOT NULL DEFAULT tstzrange(now(), NULL, '[)'),
              status        text        NOT NULL DEFAULT 'draft',
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT price_lists_code_uq UNIQUE (code),
              CONSTRAINT price_lists_scope_chk
                CHECK (scope IN ('base','tier','company','promotion')),
              CONSTRAINT price_lists_status_chk CHECK (status IN ('draft','active','archived')),
              CONSTRAINT price_lists_validity_chk CHECK (NOT isempty(validity)),
              CONSTRAINT price_lists_coherence_chk CHECK (
                  (scope = 'base'      AND price_tier_id IS NULL AND company_id IS NULL)
               OR (scope = 'tier'      AND price_tier_id IS NOT NULL)
               OR (scope = 'company'   AND company_id IS NOT NULL)
               OR (scope = 'promotion' AND promotion_id IS NOT NULL)
              ),

              -- overlapping active windows for the same audience are now IMPOSSIBLE
              CONSTRAINT price_lists_no_tier_overlap
                EXCLUDE USING gist (price_tier_id WITH =, currency WITH =, validity WITH &&)
                WHERE (scope = 'tier' AND status = 'active'),
              CONSTRAINT price_lists_no_company_overlap
                EXCLUDE USING gist (company_id WITH =, currency WITH =,
                                    has_contract WITH =, validity WITH &&)
                WHERE (scope = 'company' AND status = 'active'),
              CONSTRAINT price_lists_no_base_overlap
                EXCLUDE USING gist (currency WITH =, validity WITH &&)
                WHERE (scope = 'base' AND status = 'active')
            );

            CREATE INDEX price_lists_company_idx ON price_lists (company_id)
              INCLUDE (priority, has_contract) WHERE scope = 'company' AND status = 'active';
            CREATE INDEX price_lists_tier_idx    ON price_lists (price_tier_id)
              INCLUDE (priority)               WHERE scope = 'tier'    AND status = 'active';
            CREATE INDEX price_lists_validity_gist ON price_lists USING gist (validity)
              WHERE status = 'active';
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS price_lists CASCADE');
    }
};
