<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.1 (signed off 2026-09-20) — role_user. Composite PK serves "roles
 * held by this user"; role_user_role_idx is the reverse direction, mirroring
 * company_users' own PK-plus-reverse-index shape (§4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE role_user (
              role_id            bigint      NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
              user_id            bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              granted_by_user_id bigint      REFERENCES users (id),
              created_at         timestamptz NOT NULL DEFAULT now(),

              PRIMARY KEY (user_id, role_id)
            );

            CREATE INDEX role_user_role_idx ON role_user (role_id, user_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS role_user CASCADE');
    }
};
