<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.3 (signed off 2026-09-20) — carts. Pre-order state; company_id and
 * user_id are both nullable (a guest has neither yet). session_token is how a
 * guest cart is merged onto a company/user at login.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE carts (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id    text        NOT NULL,
              session_token text       NOT NULL,
              company_id   bigint      REFERENCES companies (id),
              user_id      bigint      REFERENCES users (id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT carts_public_id_uq    UNIQUE (public_id),
              CONSTRAINT carts_session_token_uq UNIQUE (session_token)
            );

            CREATE INDEX carts_company_updated_idx ON carts (company_id, updated_at)
              WHERE company_id IS NOT NULL;
            CREATE INDEX carts_user_idx ON carts (user_id) WHERE user_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS carts CASCADE');
    }
};
