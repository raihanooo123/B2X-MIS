<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §4.4 — company_users.
 *
 * Trade-account role membership. This is the only role/permission construct
 * defined in Doc 02 for Identity & Access — there is no `roles` /
 * `role_user` / `permissions` DDL in the document (Appendix A names
 * `roles`/`role_user` in the migration-ordering list but never defines
 * them, and `permissions` is not referenced anywhere). Scaffolding those
 * would invent schema not in the ERD, so this migration set stops at what
 * is actually specified.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE company_users (
              company_id         bigint      NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
              user_id            bigint      NOT NULL REFERENCES users (id)     ON DELETE CASCADE,
              role               text        NOT NULL DEFAULT 'buyer',
              order_limit_minor  bigint,
              requires_approval  boolean     NOT NULL DEFAULT false,
              is_default_contact boolean     NOT NULL DEFAULT false,
              created_at         timestamptz NOT NULL DEFAULT now(),

              PRIMARY KEY (company_id, user_id),
              CONSTRAINT company_users_role_chk
                CHECK (role IN ('owner','buyer','approver','viewer'))
            );

            CREATE INDEX company_users_user_idx ON company_users (user_id, company_id);
            CREATE UNIQUE INDEX company_users_default_contact_uq
              ON company_users (company_id) WHERE is_default_contact;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS company_users CASCADE');
    }
};
