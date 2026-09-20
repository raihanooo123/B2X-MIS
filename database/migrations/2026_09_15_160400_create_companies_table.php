<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §4.3 — companies.
 *
 * `price_tier_id` is intentionally NOT a foreign key yet: `price_tiers`
 * belongs to the Pricing & Tax context (Doc 02 §6.3) and is out of scope
 * for this migration set. The column, type and index shape match the doc
 * exactly; the constraint is added in the migration that creates
 * `price_tiers`, per the doc's own precedent for forward references
 * (Appendix A, migration-ordering note under step 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE companies (
              id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id              text        NOT NULL,
              account_code           text        NOT NULL,
              name                   text        NOT NULL,
              trading_name           text,
              vat_number             text,
              registration_number    text,
              status                 text        NOT NULL DEFAULT 'applied',
              price_tier_id          bigint,
              payment_terms          text        NOT NULL DEFAULT 'prepay',
              credit_limit_minor     bigint      NOT NULL DEFAULT 0,
              credit_used_minor      bigint      NOT NULL DEFAULT 0,
              credit_held_minor      bigint      NOT NULL DEFAULT 0,
              account_balance_minor  bigint      NOT NULL DEFAULT 0,
              tax_exempt             boolean     NOT NULL DEFAULT false,
              price_display_mode     text        NOT NULL DEFAULT 'net',
              assigned_rep_user_id   bigint      REFERENCES users (id),
              xero_contact_id        uuid,
              approved_at            timestamptz,
              approved_by_user_id    bigint      REFERENCES users (id),
              created_at             timestamptz NOT NULL DEFAULT now(),
              updated_at             timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT companies_status_chk
                CHECK (status IN ('applied','approved','rejected','suspended','closed')),
              CONSTRAINT companies_terms_chk
                CHECK (payment_terms IN ('prepay','net7','net14','net30','net60')),
              CONSTRAINT companies_display_chk CHECK (price_display_mode IN ('net','gross')),
              CONSTRAINT companies_credit_chk  CHECK (credit_limit_minor    >= 0),
              CONSTRAINT companies_held_chk    CHECK (credit_held_minor     >= 0),
              CONSTRAINT companies_balance_chk CHECK (account_balance_minor >= 0)
            );

            COMMENT ON COLUMN companies.price_tier_id IS
              'FK to price_tiers(id) pending — table scaffolded with the Pricing & Tax context (Doc 02 §6.3).';

            CREATE UNIQUE INDEX companies_account_code_uq ON companies (account_code);
            CREATE UNIQUE INDEX companies_public_id_uq    ON companies (public_id);
            CREATE UNIQUE INDEX companies_vat_uq          ON companies (vat_number)
              WHERE vat_number IS NOT NULL;
            CREATE INDEX companies_status_name_idx ON companies (status, name);
            CREATE INDEX companies_tier_idx        ON companies (price_tier_id, status)
              WHERE price_tier_id IS NOT NULL;
            CREATE INDEX companies_rep_idx         ON companies (assigned_rep_user_id, status)
              WHERE assigned_rep_user_id IS NOT NULL;
            CREATE INDEX companies_name_trgm_idx   ON companies USING gin (name gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS companies CASCADE');
    }
};
