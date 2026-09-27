<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02 §25.4–25.5 — VAT number (HMRC and VIES) and Companies House check
 * evidence, one row per attempt. Tables only: the checks themselves are
 * slice B2. `company_status` and `company_type` carry no CHECK on purpose
 * — they are Companies House's vocabulary, stored verbatim (02 §25.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE vat_number_checks (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              b2b_application_id    bigint      NOT NULL REFERENCES b2b_applications (id) ON DELETE CASCADE,
              vat_number            text        NOT NULL,
              authority             text        NOT NULL,
              outcome               text        NOT NULL,
              registered_name       text,
              registered_address    jsonb,
              consultation_number   text,
              processed_at          timestamptz,
              failure_reason        text,
              requested_by_user_id  bigint      REFERENCES users (id),
              checked_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT vat_number_checks_number_chk  CHECK (vat_number ~ '^(GB|XI)[0-9]{9}([0-9]{3})?$'),
              CONSTRAINT vat_number_checks_authority_chk CHECK (authority IN ('hmrc','vies')),
              CONSTRAINT vat_number_checks_authority_prefix_chk CHECK (
                (authority = 'hmrc') = (left(vat_number, 2) = 'GB')
              ),
              CONSTRAINT vat_number_checks_outcome_chk CHECK (outcome IN ('valid','not_found','unchecked')),
              CONSTRAINT vat_number_checks_failure_chk CHECK (failure_reason IN
                ('timeout','unavailable','rate_limited','not_configured','unexpected_response')),
              CONSTRAINT vat_number_checks_coherence_chk CHECK (
                CASE outcome
                  WHEN 'valid'     THEN processed_at IS NOT NULL AND failure_reason IS NULL
                                    AND (authority = 'vies' OR registered_name IS NOT NULL)
                  WHEN 'not_found' THEN registered_name IS NULL AND registered_address IS NULL
                                    AND consultation_number IS NULL AND failure_reason IS NULL
                  ELSE registered_name IS NULL AND registered_address IS NULL
                   AND consultation_number IS NULL AND processed_at IS NULL AND failure_reason IS NOT NULL
                END)
            );

            CREATE INDEX vat_number_checks_latest_idx
              ON vat_number_checks (b2b_application_id, checked_at DESC, id);

            CREATE TABLE companies_house_checks (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              b2b_application_id    bigint      NOT NULL REFERENCES b2b_applications (id) ON DELETE CASCADE,
              company_number        text        NOT NULL,
              outcome               text        NOT NULL,
              company_status        text,
              company_type          text,
              registered_name       text,
              registered_office     jsonb,
              incorporated_on       date,
              failure_reason        text,
              requested_by_user_id  bigint      REFERENCES users (id),
              checked_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT companies_house_checks_number_chk  CHECK (company_number ~ '^([0-9]{8}|[A-Z]{2}[0-9]{6})$'),
              CONSTRAINT companies_house_checks_outcome_chk CHECK (outcome IN ('found','not_found','unchecked')),
              CONSTRAINT companies_house_checks_failure_chk CHECK (failure_reason IN
                ('timeout','unavailable','rate_limited','not_configured','unexpected_response')),
              CONSTRAINT companies_house_checks_coherence_chk CHECK (
                CASE outcome
                  WHEN 'found' THEN company_status IS NOT NULL AND registered_name IS NOT NULL AND failure_reason IS NULL
                  WHEN 'not_found' THEN company_status IS NULL AND registered_name IS NULL AND failure_reason IS NULL
                  ELSE company_status IS NULL AND registered_name IS NULL AND registered_office IS NULL
                   AND failure_reason IS NOT NULL
                END)
            );

            CREATE INDEX companies_house_checks_latest_idx
              ON companies_house_checks (b2b_application_id, checked_at DESC, id);

            INSERT INTO system_configurations (config_key, scope, value_type, value_int, description)
            VALUES ('applications.verification_max_age_days', 'global', 'int', 30,
                    'Days before VAT and Companies House evidence counts as stale at approval (02 §25.9).')
            ON CONFLICT ON CONSTRAINT system_configurations_key_scope_uq DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM system_configurations WHERE config_key = 'applications.verification_max_age_days' AND scope = 'global';
            DROP TABLE IF EXISTS companies_house_checks;
            DROP TABLE IF EXISTS vat_number_checks;
        SQL);
    }
};
