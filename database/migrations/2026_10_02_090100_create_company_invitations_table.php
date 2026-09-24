<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §17.1 (signed off 2026-09-24) — company_invitations. The emailed
 * token is never stored, only its SHA-256 (`token_hash`). Open, accepted,
 * revoked and expired are derived from the timestamps; the CHECKs keep
 * each timestamp paired with its actor and make "accepted and revoked"
 * impossible. `company_invitations_open_uq` allows one open invitation
 * per company and address while keeping the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE company_invitations (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id           text        NOT NULL,
              company_id          bigint      NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
              email               citext      NOT NULL,
              first_name          text        NOT NULL,
              last_name           text        NOT NULL,
              role                text        NOT NULL DEFAULT 'buyer',
              order_limit_minor   bigint,
              requires_approval   boolean     NOT NULL DEFAULT false,
              token_hash          text        NOT NULL,
              invited_by_user_id  bigint      NOT NULL REFERENCES users (id),
              accepted_by_user_id bigint      REFERENCES users (id),
              expires_at          timestamptz NOT NULL,
              accepted_at         timestamptz,
              revoked_at          timestamptz,
              revoked_by_user_id  bigint      REFERENCES users (id),
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT company_invitations_public_id_uq  UNIQUE (public_id),
              CONSTRAINT company_invitations_token_uq      UNIQUE (token_hash),
              CONSTRAINT company_invitations_role_chk
                CHECK (role IN ('owner','buyer','approver','viewer')),
              CONSTRAINT company_invitations_limit_chk
                CHECK (order_limit_minor IS NULL OR order_limit_minor >= 0),
              CONSTRAINT company_invitations_expiry_chk CHECK (expires_at > created_at),
              CONSTRAINT company_invitations_outcome_chk CHECK (
                  NOT (accepted_at IS NOT NULL AND revoked_at IS NOT NULL)
              ),
              CONSTRAINT company_invitations_accepted_chk CHECK (
                  (accepted_at IS NULL) = (accepted_by_user_id IS NULL)
              ),
              CONSTRAINT company_invitations_revoked_chk CHECK (
                  (revoked_at IS NULL) = (revoked_by_user_id IS NULL)
              )
            );

            CREATE UNIQUE INDEX company_invitations_open_uq
              ON company_invitations (company_id, email)
              WHERE accepted_at IS NULL AND revoked_at IS NULL;
            CREATE INDEX company_invitations_company_idx
              ON company_invitations (company_id, created_at DESC);
            CREATE INDEX company_invitations_email_open_idx
              ON company_invitations (email)
              WHERE accepted_at IS NULL AND revoked_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS company_invitations CASCADE');
    }
};
