<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.5.2 (signed off 2026-09-20) — invoices. Gapless numbering via
 * number_sequences (CLAUDE.md invariant 8) — seed a new 'invoice_number'
 * key_name row before issuing numbers against it; not done by this
 * migration.
 *
 * `shipment_id` is intentionally NOT a foreign key yet: `shipments` (§14.6)
 * is drafted but not signed off, and Appendix A places invoices in Phase 1
 * but shipments in Phase 2 — a cross-phase dependency. Mirrors the
 * order_lines.sku_cost_id precedent (§8.3, resolved in migration
 * 2026_09_20_090200_add_sku_cost_fk_to_order_lines_table): add the real FK
 * via a follow-up migration once shipments exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE invoices (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id           text        NOT NULL,
              invoice_number      text        NOT NULL,
              company_id          bigint      NOT NULL REFERENCES companies (id),
              order_id            bigint      NOT NULL REFERENCES orders (id),
              shipment_id         bigint,
              status              text        NOT NULL DEFAULT 'issued',
              currency            char(3)     NOT NULL DEFAULT 'GBP',
              subtotal_net_minor  bigint      NOT NULL DEFAULT 0,
              discount_net_minor  bigint      NOT NULL DEFAULT 0,
              shipping_net_minor  bigint      NOT NULL DEFAULT 0,
              tax_minor           bigint      NOT NULL DEFAULT 0,
              total_gross_minor   bigint      NOT NULL DEFAULT 0,
              paid_minor          bigint      NOT NULL DEFAULT 0,
              payment_terms       text        NOT NULL,
              due_at              timestamptz NOT NULL,
              issued_at           timestamptz NOT NULL DEFAULT now(),
              xero_invoice_id     uuid,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT invoices_number_uq    UNIQUE (invoice_number),
              CONSTRAINT invoices_public_id_uq UNIQUE (public_id),
              CONSTRAINT invoices_status_chk CHECK (status IN
                ('issued','part_paid','paid','overdue','credited','void')),
              CONSTRAINT invoices_terms_chk CHECK (payment_terms IN
                ('prepay','net7','net14','net30','net60')),
              CONSTRAINT invoices_totals_chk CHECK (total_gross_minor >= 0 AND paid_minor >= 0)
            );

            COMMENT ON COLUMN invoices.shipment_id IS
              'FK to shipments(id) pending — shipments (Doc 02 §14.6) is fully specified but not yet signed off or scaffolded in any session.';

            CREATE INDEX invoices_company_issued_idx ON invoices (company_id, issued_at DESC);
            CREATE INDEX invoices_order_idx    ON invoices (order_id);
            CREATE INDEX invoices_shipment_idx ON invoices (shipment_id) WHERE shipment_id IS NOT NULL;
            CREATE INDEX invoices_unpaid_idx   ON invoices (company_id)
              INCLUDE (total_gross_minor, paid_minor)
              WHERE status IN ('issued','part_paid','overdue');
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS invoices CASCADE');
    }
};
