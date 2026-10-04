<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §26.2 (signed off 2026-10-04, 05.15 §9 A2) — a guest accepts the
 * terms of sale at checkout with no account. The acceptance is identified
 * by its order; only a checkout acceptance may have no user.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE terms_acceptances ALTER COLUMN user_id DROP NOT NULL;

            ALTER TABLE terms_acceptances ADD CONSTRAINT terms_acceptances_user_chk CHECK (
              user_id IS NOT NULL OR source = 'checkout'
            ) NOT VALID;
            ALTER TABLE terms_acceptances VALIDATE CONSTRAINT terms_acceptances_user_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE terms_acceptances DROP CONSTRAINT IF EXISTS terms_acceptances_user_chk;
            ALTER TABLE terms_acceptances ALTER COLUMN user_id SET NOT NULL;
        SQL);
    }
};
