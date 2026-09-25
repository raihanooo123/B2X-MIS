<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §21 (signed off 2026-09-25) — invoicing.
 *
 *   - §21.1: `invoice` joins the attachable types, so an issued document's
 *     PDF is archived where the customer's copy can be served from.
 *   - §21.2: a public customer's order gets a receipt — an `invoices` row
 *     with no company, no payment terms and no due date. The three go
 *     NULL together or not at all (`invoices_kind_chk`).
 *
 * Both constraints are added NOT VALID then VALIDATEd (§2.5): every
 * existing row already satisfies them, and neither takes a long lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attachments DROP CONSTRAINT attachments_attachable_type_chk;
            ALTER TABLE attachments ADD CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
              ('b2b_application','rma','purchase_order','container','product','sku','invoice')) NOT VALID;
            ALTER TABLE attachments VALIDATE CONSTRAINT attachments_attachable_type_chk;

            ALTER TABLE invoices
              ALTER COLUMN company_id    DROP NOT NULL,
              ALTER COLUMN payment_terms DROP NOT NULL,
              ALTER COLUMN due_at        DROP NOT NULL;

            ALTER TABLE invoices ADD CONSTRAINT invoices_kind_chk CHECK (
                (company_id IS NOT NULL AND payment_terms IS NOT NULL AND due_at IS NOT NULL)
             OR (company_id IS NULL     AND payment_terms IS NULL     AND due_at IS NULL)
            ) NOT VALID;
            ALTER TABLE invoices VALIDATE CONSTRAINT invoices_kind_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_kind_chk;
            ALTER TABLE invoices
              ALTER COLUMN company_id    SET NOT NULL,
              ALTER COLUMN payment_terms SET NOT NULL,
              ALTER COLUMN due_at        SET NOT NULL;

            ALTER TABLE attachments DROP CONSTRAINT attachments_attachable_type_chk;
            ALTER TABLE attachments ADD CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
              ('b2b_application','rma','purchase_order','container','product','sku'));
        SQL);
    }
};
