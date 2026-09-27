<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05.2 §5.6 step 3: an approved company's `account_code` comes from
 * `number_sequences` (02 §11.3). Provisions the series, as
 * 2026_10_10_090100 does for `po_number`; NumberSequenceService never
 * starts a series on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO number_sequences (key_name, prefix, next_value, padding)
            VALUES ('account_code', 'ACC-', 1, 6)
            ON CONFLICT (key_name) DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM number_sequences WHERE key_name = 'account_code'");
    }
};
