<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §19 (signed off 2026-09-24) — a public customer has no company,
 * so their card payment carries its order instead. Every payment keeps
 * an owner: `payments_owner_chk`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments ALTER COLUMN company_id DROP NOT NULL;

            ALTER TABLE payments ADD CONSTRAINT payments_owner_chk
              CHECK (company_id IS NOT NULL OR order_id IS NOT NULL) NOT VALID;
            ALTER TABLE payments VALIDATE CONSTRAINT payments_owner_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_owner_chk;
            ALTER TABLE payments ALTER COLUMN company_id SET NOT NULL;
        SQL);
    }
};
