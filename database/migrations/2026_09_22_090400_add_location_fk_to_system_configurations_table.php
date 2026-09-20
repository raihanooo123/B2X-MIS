<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §2.7 / §7.3 — enforces `system_configurations.location_id
 * REFERENCES locations (id)` now that `locations` exists. The column,
 * its type and its coherence CHECK were already created with
 * `system_configurations`; this only adds the constraint that was
 * deferred pending this table. This is the last remaining deferred FK
 * from the original Reference Data / Identity & Access migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE system_configurations ADD CONSTRAINT system_configurations_location_id_fk
              FOREIGN KEY (location_id) REFERENCES locations (id)
        SQL);

        DB::statement('COMMENT ON COLUMN system_configurations.location_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE system_configurations DROP CONSTRAINT IF EXISTS system_configurations_location_id_fk');
    }
};
