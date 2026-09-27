<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02 §25.2 — rejection outcome, the re-application date snapshot and the
 * cooling-period setting. Existing rejections are backfilled from their
 * `application.rejected` audit rows (05.2 §16), which carry `remediable`;
 * one with no audit row is treated as not remediable. Constraints are
 * added NOT VALID and validated after the backfill (07 §11.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE b2b_applications
              ADD COLUMN rejection_category   text,
              ADD COLUMN rejection_remediable boolean,
              ADD COLUMN applicant_message    text,
              ADD COLUMN reapply_after        timestamptz;

            UPDATE b2b_applications a
               SET rejection_category = 'other',
                   rejection_remediable = COALESCE((
                     SELECT (l.after ->> 'remediable')::boolean
                       FROM audit_log l
                      WHERE l.action = 'application.rejected'
                        AND l.subject_type = 'b2b_application'
                        AND l.subject_id = a.id
                      ORDER BY l.occurred_at DESC, l.id DESC
                      LIMIT 1), false)
             WHERE a.status = 'rejected';

            UPDATE b2b_applications
               SET reapply_after = CASE WHEN rejection_remediable THEN NULL
                                        ELSE COALESCE(reviewed_at, submitted_at) + interval '90 days' END
             WHERE status = 'rejected';

            ALTER TABLE b2b_applications ADD CONSTRAINT b2b_applications_rejection_category_chk
              CHECK (rejection_category IN ('not_a_trade_business','business_not_verified','identity_not_verified',
                'duplicate_account','no_response_to_request','outside_trading_area','credit_or_risk_concern','other')) NOT VALID;
            ALTER TABLE b2b_applications ADD CONSTRAINT b2b_applications_rejection_outcome_chk CHECK (
              CASE WHEN status = 'rejected'
                THEN rejection_category IS NOT NULL
                 AND rejection_remediable IS NOT NULL
                 AND (rejection_remediable = (reapply_after IS NULL))
                ELSE rejection_category IS NULL AND rejection_remediable IS NULL
                 AND applicant_message IS NULL AND reapply_after IS NULL
              END) NOT VALID;
            ALTER TABLE b2b_applications ADD CONSTRAINT b2b_applications_applicant_message_chk
              CHECK (applicant_message IS NULL OR length(applicant_message) BETWEEN 1 AND 2000) NOT VALID;
            ALTER TABLE b2b_applications VALIDATE CONSTRAINT b2b_applications_rejection_category_chk;
            ALTER TABLE b2b_applications VALIDATE CONSTRAINT b2b_applications_rejection_outcome_chk;
            ALTER TABLE b2b_applications VALIDATE CONSTRAINT b2b_applications_applicant_message_chk;

            CREATE INDEX b2b_applications_reapply_idx
              ON b2b_applications (applicant_user_id, reviewed_at DESC)
              INCLUDE (reapply_after)
              WHERE status = 'rejected' AND applicant_user_id IS NOT NULL;

            INSERT INTO system_configurations (config_key, scope, value_type, value_int, description)
            VALUES ('applications.reapply_cooling_days', 'global', 'int', 90,
                    'Days a rejected applicant waits before applying again, unless the rejection is remediable (05.2 §5.7, 02 §25.2).')
            ON CONFLICT ON CONSTRAINT system_configurations_key_scope_uq DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM system_configurations WHERE config_key = 'applications.reapply_cooling_days' AND scope = 'global';
            DROP INDEX IF EXISTS b2b_applications_reapply_idx;
            ALTER TABLE b2b_applications
              DROP CONSTRAINT IF EXISTS b2b_applications_applicant_message_chk,
              DROP CONSTRAINT IF EXISTS b2b_applications_rejection_outcome_chk,
              DROP CONSTRAINT IF EXISTS b2b_applications_rejection_category_chk,
              DROP COLUMN IF EXISTS reapply_after,
              DROP COLUMN IF EXISTS applicant_message,
              DROP COLUMN IF EXISTS rejection_remediable,
              DROP COLUMN IF EXISTS rejection_category;
        SQL);
    }
};
