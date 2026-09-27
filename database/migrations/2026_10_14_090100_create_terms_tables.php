<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02 §25.1 — terms versions and their acceptance. Versions are immutable
 * (no UPDATE or DELETE); acceptances are append-only (no UPDATE; DELETE
 * only through the application's cascade). `terms_acceptances` references
 * `orders`, so it follows the orders migrations (02 Appendix A step 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_row_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
            END;
            $$;

            CREATE TABLE terms_versions (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              kind                  text        NOT NULL,
              version               text        NOT NULL,
              body_markdown         text        NOT NULL,
              body_sha256           text        NOT NULL,
              effective_from        timestamptz NOT NULL,
              published_by_user_id  bigint      NOT NULL REFERENCES users (id),
              created_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT terms_versions_kind_chk    CHECK (kind IN ('trade','sale')),
              CONSTRAINT terms_versions_version_chk CHECK (version ~ '^[0-9A-Za-z._-]{1,32}$'),
              CONSTRAINT terms_versions_sha_chk     CHECK (body_sha256 ~ '^[0-9a-f]{64}$'),
              CONSTRAINT terms_versions_kind_version_uq   UNIQUE (kind, version),
              CONSTRAINT terms_versions_kind_effective_uq UNIQUE (kind, effective_from)
            );

            CREATE TRIGGER terms_versions_immutable
              BEFORE UPDATE OR DELETE ON terms_versions
              FOR EACH ROW EXECUTE FUNCTION reject_row_mutation();

            CREATE TABLE terms_acceptances (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              terms_version_id    bigint      NOT NULL REFERENCES terms_versions (id),
              user_id             bigint      NOT NULL REFERENCES users (id),
              b2b_application_id  bigint      REFERENCES b2b_applications (id) ON DELETE CASCADE,
              order_id            bigint      REFERENCES orders (id),
              source              text        NOT NULL,
              ip                  inet,
              user_agent          text,
              accepted_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT terms_acceptances_source_chk CHECK (source IN ('trade_application','checkout')),
              CONSTRAINT terms_acceptances_source_subject_chk CHECK (
                CASE source
                  WHEN 'trade_application' THEN b2b_application_id IS NOT NULL AND order_id IS NULL
                  WHEN 'checkout'          THEN order_id IS NOT NULL AND b2b_application_id IS NULL
                END
              )
            );

            CREATE UNIQUE INDEX terms_acceptances_application_uq
              ON terms_acceptances (b2b_application_id) WHERE b2b_application_id IS NOT NULL;
            CREATE UNIQUE INDEX terms_acceptances_order_uq
              ON terms_acceptances (order_id) WHERE order_id IS NOT NULL;

            CREATE TRIGGER terms_acceptances_immutable
              BEFORE UPDATE ON terms_acceptances
              FOR EACH ROW EXECUTE FUNCTION reject_row_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS terms_acceptances;
            DROP TABLE IF EXISTS terms_versions;
            DROP FUNCTION IF EXISTS reject_row_mutation();
        SQL);
    }
};
