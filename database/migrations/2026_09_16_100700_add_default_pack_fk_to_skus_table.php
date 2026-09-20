<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.5 — the skus <-> packs circular reference, resolved with a
 * deferred foreign key: both rows are inserted in one transaction and the
 * constraint is checked at commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE skus ADD CONSTRAINT skus_default_pack_fk
              FOREIGN KEY (default_pack_id) REFERENCES packs (id)
              DEFERRABLE INITIALLY DEFERRED
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE skus DROP CONSTRAINT IF EXISTS skus_default_pack_fk');
    }
};
