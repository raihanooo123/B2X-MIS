<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.5.2 — enforces `invoices.shipment_id REFERENCES shipments (id)`
 * now that `shipments` (§14.6) exists. The column and
 * `invoices_shipment_idx` were created with `invoices`; this only adds the
 * constraint deferred pending this table, mirroring the
 * order_lines.sku_cost_id precedent (2026_09_20_090200).
 *
 * Added NOT VALID then VALIDATEd (§2.5): VALIDATE takes only a SHARE UPDATE
 * EXCLUSIVE lock, so invoicing is not blocked while existing rows are
 * checked. Every existing row has `shipment_id IS NULL` — nothing issues a
 * per-shipment invoice yet — so validation cannot fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices ADD CONSTRAINT invoices_shipment_id_fk
              FOREIGN KEY (shipment_id) REFERENCES shipments (id) NOT VALID;
            ALTER TABLE invoices VALIDATE CONSTRAINT invoices_shipment_id_fk;

            COMMENT ON COLUMN invoices.shipment_id IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_shipment_id_fk');
    }
};
