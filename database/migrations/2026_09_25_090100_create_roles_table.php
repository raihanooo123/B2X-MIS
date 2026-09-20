<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.1 (signed off 2026-09-20) — roles. Internal staff RBAC, distinct
 * from company_users.role (§4.4), the B2B customer-side role. Six-value
 * closed list matching 07-nfr.md §6.1 exactly (sales_manager added by the
 * 2026-09-17 correction there).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (
              id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              code                    text     NOT NULL,
              name                    text     NOT NULL,
              default_max_discount_bp smallint,
              created_at              timestamptz NOT NULL DEFAULT now(),
              updated_at              timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT roles_code_uq UNIQUE (code),
              CONSTRAINT roles_code_chk CHECK (code IN
                ('admin','accounts','purchasing','rep','warehouse','sales_manager')),
              CONSTRAINT roles_discount_chk
                CHECK (default_max_discount_bp IS NULL OR default_max_discount_bp <= 10000)
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS roles CASCADE');
    }
};
