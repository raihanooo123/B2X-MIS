<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §17.2 (signed off 2026-09-24) — user_two_factor_recovery_codes.
 * One row per code, Argon2id-hashed, spent by setting `used_at` — the
 * single-use guarantee and the audit fact in one column. The partial
 * index finds a user's unused codes, the only rows verification reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE user_two_factor_recovery_codes (
              id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              code_hash   text        NOT NULL,
              used_at     timestamptz,
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX user_2fa_recovery_unused_idx
              ON user_two_factor_recovery_codes (user_id)
              WHERE used_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS user_two_factor_recovery_codes CASCADE');
    }
};
