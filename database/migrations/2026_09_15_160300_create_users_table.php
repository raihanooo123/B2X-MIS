<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §4.2 — users.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
              id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id               text        NOT NULL,
              email                   citext      NOT NULL,
              password_hash           text,
              first_name              text        NOT NULL,
              last_name               text        NOT NULL,
              phone                   text,
              status                  text        NOT NULL DEFAULT 'pending',
              email_verified_at       timestamptz,
              two_factor_secret       bytea,
              two_factor_enabled      boolean     NOT NULL DEFAULT false,
              last_login_at           timestamptz,
              locale                  text        NOT NULL DEFAULT 'en_GB',
              default_max_discount_bp smallint,
              created_at              timestamptz NOT NULL DEFAULT now(),
              updated_at              timestamptz NOT NULL DEFAULT now(),
              deleted_at              timestamptz,

              CONSTRAINT users_status_chk
                CHECK (status IN ('pending','active','suspended','closed')),
              CONSTRAINT users_public_id_len_chk CHECK (length(public_id) = 26),
              CONSTRAINT users_discount_chk
                CHECK (default_max_discount_bp IS NULL OR default_max_discount_bp <= 10000)
            );

            CREATE UNIQUE INDEX users_public_id_uq ON users (public_id);
            CREATE UNIQUE INDEX users_email_uq     ON users (email) WHERE deleted_at IS NULL;
            CREATE INDEX users_status_created_idx  ON users (status, created_at);
            CREATE INDEX users_last_login_idx      ON users (last_login_at)
              WHERE last_login_at IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS users CASCADE');
    }
};
