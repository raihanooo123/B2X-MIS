<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.7 — sku_attribute_values. `sku_attribute_values_facet_idx` is
 * the reverse direction of the composite PK — both directions of a
 * many-to-many, always (§5.7's own rule) — and is what makes faceted
 * filtering ("every SKU where colour = blue") an index-only scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE sku_attribute_values (
              sku_id             bigint NOT NULL REFERENCES skus (id) ON DELETE CASCADE,
              attribute_id       bigint NOT NULL REFERENCES attributes (id),
              attribute_value_id bigint REFERENCES attribute_values (id),
              value_text         text,
              value_numeric      numeric(14,4),
              PRIMARY KEY (sku_id, attribute_id)
            );

            CREATE INDEX sku_attribute_values_facet_idx
              ON sku_attribute_values (attribute_value_id, sku_id)
              WHERE attribute_value_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sku_attribute_values CASCADE');
    }
};
