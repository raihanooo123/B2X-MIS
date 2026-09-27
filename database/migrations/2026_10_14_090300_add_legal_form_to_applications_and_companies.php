<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02 §25.3 — legal form, separate from business type. Rows with a
 * Companies House number are classified from its register prefix; rows
 * without one stay NULL. New applications must supply it
 * (RegisterTradeRequest), which the column cannot require without failing
 * every later update of an unclassified historical row.
 */
return new class extends Migration
{
    private const CLASSIFY = <<<'SQL'
        CASE
          WHEN upper(registration_number) ~ '^(OC|SO|NC)' THEN 'llp'
          WHEN upper(registration_number) ~ '^(SL|LP|NL)' THEN 'partnership'
          WHEN upper(registration_number) ~ '^([0-9]{8}|(SC|NI)[0-9]{6})$' THEN 'limited_company'
          ELSE 'other'
        END
        SQL;

    public function up(): void
    {
        DB::unprepared('ALTER TABLE b2b_applications ADD COLUMN legal_form text; ALTER TABLE companies ADD COLUMN legal_form text;');

        DB::unprepared('UPDATE b2b_applications SET legal_form = '.self::CLASSIFY.' WHERE registration_number IS NOT NULL');
        DB::unprepared('UPDATE companies SET legal_form = '.self::CLASSIFY.' WHERE registration_number IS NOT NULL');

        DB::unprepared(<<<'SQL'
            ALTER TABLE b2b_applications ADD CONSTRAINT b2b_applications_legal_form_chk
              CHECK (legal_form IN ('sole_trader','partnership','limited_company','llp','other')) NOT VALID;
            ALTER TABLE b2b_applications ADD CONSTRAINT b2b_applications_legal_form_number_chk
              CHECK (legal_form IS NULL OR legal_form NOT IN ('limited_company','llp')
                     OR registration_number IS NOT NULL) NOT VALID;
            ALTER TABLE companies ADD CONSTRAINT companies_legal_form_chk
              CHECK (legal_form IN ('sole_trader','partnership','limited_company','llp','other')) NOT VALID;
            ALTER TABLE b2b_applications VALIDATE CONSTRAINT b2b_applications_legal_form_chk;
            ALTER TABLE b2b_applications VALIDATE CONSTRAINT b2b_applications_legal_form_number_chk;
            ALTER TABLE companies        VALIDATE CONSTRAINT companies_legal_form_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_legal_form_chk, DROP COLUMN IF EXISTS legal_form;
            ALTER TABLE b2b_applications
              DROP CONSTRAINT IF EXISTS b2b_applications_legal_form_number_chk,
              DROP CONSTRAINT IF EXISTS b2b_applications_legal_form_chk,
              DROP COLUMN IF EXISTS legal_form;
        SQL);
    }
};
