<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §4.6 — b2b_applications. `info_requested` is included directly in
 * the status CHECK per 05.2 §4.5 — no later ALTER needed, since the table
 * doesn't exist yet. `address jsonb` + GIN index replaces the MySQL
 * edition's opaque address_json for duplicate-detection queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE b2b_applications (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id           text        NOT NULL,
              company_id          bigint      REFERENCES companies (id),
              applicant_user_id   bigint      REFERENCES users (id),
              company_name        text        NOT NULL,
              vat_number          text,
              registration_number text,
              contact_name        text        NOT NULL,
              contact_email       citext      NOT NULL,
              contact_phone       text,
              business_type       text,
              estimated_monthly_spend_minor bigint,
              address             jsonb       NOT NULL,
              status              text        NOT NULL DEFAULT 'submitted',
              requested_tier_id   bigint      REFERENCES price_tiers (id),
              granted_tier_id     bigint      REFERENCES price_tiers (id),
              reviewer_user_id    bigint      REFERENCES users (id),
              review_note         text,
              info_request        text,
              reviewed_at         timestamptz,
              submitted_at        timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT b2b_applications_status_chk CHECK (status IN
                ('submitted','in_review','info_requested','approved','rejected','withdrawn'))
            );

            CREATE UNIQUE INDEX b2b_applications_public_id_uq ON b2b_applications (public_id);
            CREATE INDEX b2b_applications_queue_idx ON b2b_applications (status, submitted_at)
              WHERE status IN ('submitted','in_review','info_requested');
            CREATE INDEX b2b_applications_email_idx ON b2b_applications (contact_email);
            CREATE INDEX b2b_applications_company_idx ON b2b_applications (company_id)
              WHERE company_id IS NOT NULL;
            CREATE INDEX b2b_applications_address_gin ON b2b_applications USING gin (address);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS b2b_applications CASCADE');
    }
};
