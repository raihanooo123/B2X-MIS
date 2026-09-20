<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §4.3 / §6.3 — enforces `companies.price_tier_id REFERENCES
 * price_tiers (id)` now that `price_tiers` exists. The column, its type
 * and its indexes were already created with `companies`; this only adds
 * the constraint that was deferred pending the Pricing & Tax context.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE companies ADD CONSTRAINT companies_price_tier_id_fk
              FOREIGN KEY (price_tier_id) REFERENCES price_tiers (id)
        SQL);

        DB::statement('COMMENT ON COLUMN companies.price_tier_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_price_tier_id_fk');
    }
};
