<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_log (
              id bigint GENERATED ALWAYS AS IDENTITY,
              occurred_at timestamptz NOT NULL DEFAULT now(),
              event_family text NOT NULL,
              action text NOT NULL,
              actor_type text NOT NULL,
              actor_user_id bigint,
              acting_for_company_id bigint,
              company_id bigint,
              subject_type text,
              subject_id bigint,
              before jsonb,
              after jsonb,
              reason text,
              ip inet,
              user_agent text,
              PRIMARY KEY (id, occurred_at),
              CONSTRAINT audit_log_family_chk CHECK (event_family IN
                ('auth','permission','credit_limit','price','price_override','discount_authority',
                 'fee_waiver','stock_adjustment','configuration','rep_session','rma_disposition')),
              CONSTRAINT audit_log_actor_chk CHECK (
                   (actor_type = 'user' AND actor_user_id IS NOT NULL)
                OR (actor_type IN ('system','anonymous') AND actor_user_id IS NULL)),
              CONSTRAINT audit_log_subject_chk CHECK ((subject_type IS NULL) = (subject_id IS NULL))
            ) PARTITION BY RANGE (occurred_at);

            CREATE TABLE audit_log_2026 PARTITION OF audit_log
              FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
            CREATE TABLE audit_log_2027 PARTITION OF audit_log
              FOR VALUES FROM ('2027-01-01') TO ('2028-01-01');
            CREATE TABLE audit_log_default PARTITION OF audit_log DEFAULT;

            CREATE INDEX audit_log_occurred_brin ON audit_log USING brin (occurred_at)
              WITH (pages_per_range = 32);
            CREATE INDEX audit_log_subject_idx ON audit_log (subject_type, subject_id, occurred_at)
              WHERE subject_type IS NOT NULL;
            CREATE INDEX audit_log_actor_idx ON audit_log (actor_user_id, occurred_at)
              WHERE actor_user_id IS NOT NULL;
            CREATE INDEX audit_log_company_idx ON audit_log (company_id, occurred_at)
              WHERE company_id IS NOT NULL;
            CREATE INDEX audit_log_family_idx ON audit_log (event_family, occurred_at);

            CREATE OR REPLACE FUNCTION audit_log_reject_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'audit_log is append-only';
            END;
            $$;

            CREATE TRIGGER audit_log_no_update_delete
              BEFORE UPDATE OR DELETE ON audit_log
              FOR EACH ROW EXECUTE FUNCTION audit_log_reject_mutation();
            CREATE TRIGGER audit_log_no_truncate
              BEFORE TRUNCATE ON audit_log
              FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_mutation();
            CREATE TRIGGER audit_log_2026_no_truncate
              BEFORE TRUNCATE ON audit_log_2026
              FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_mutation();
            CREATE TRIGGER audit_log_2027_no_truncate
              BEFORE TRUNCATE ON audit_log_2027
              FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_mutation();
            CREATE TRIGGER audit_log_default_no_truncate
              BEFORE TRUNCATE ON audit_log_default
              FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_log CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_reject_mutation()');
    }
};
