<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.5.3 as amended 2026-10-04 (05.15 §9 A8, signed off): credit
 * notes, including a receipt's (no company). Every credit note has an
 * owner — its company, or the order or receipt it credits.
 *
 * `rma_id` has no foreign key yet: `rmas` arrives in slice S6b (05.4 §5.1),
 * whose migration adds `credit_notes_rma_fk`. Until then nothing writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE credit_notes (
              id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id            text        NOT NULL,
              credit_note_number   text        NOT NULL,
              company_id           bigint      REFERENCES companies (id),
              order_id             bigint      REFERENCES orders (id),
              invoice_id           bigint      REFERENCES invoices (id),
              rma_id               bigint,
              reason               text        NOT NULL,
              status               text        NOT NULL DEFAULT 'issued',
              currency             char(3)     NOT NULL DEFAULT 'GBP',
              subtotal_net_minor   bigint      NOT NULL DEFAULT 0,
              tax_minor            bigint      NOT NULL DEFAULT 0,
              total_gross_minor    bigint      NOT NULL DEFAULT 0,
              xero_credit_note_id  uuid,
              issued_at            timestamptz NOT NULL DEFAULT now(),
              created_at           timestamptz NOT NULL DEFAULT now(),
              updated_at           timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT credit_notes_number_uq    UNIQUE (credit_note_number),
              CONSTRAINT credit_notes_public_id_uq UNIQUE (public_id),
              CONSTRAINT credit_notes_reason_chk CHECK (reason IN
                ('return','goodwill','pricing_correction','cancellation','other')),
              CONSTRAINT credit_notes_status_chk CHECK (status IN ('issued','void')),
              CONSTRAINT credit_notes_totals_chk CHECK (total_gross_minor >= 0),
              CONSTRAINT credit_notes_owner_chk CHECK (
                company_id IS NOT NULL OR order_id IS NOT NULL OR invoice_id IS NOT NULL)
            );

            CREATE INDEX credit_notes_company_issued_idx ON credit_notes (company_id, issued_at DESC);
            CREATE INDEX credit_notes_order_idx   ON credit_notes (order_id)   WHERE order_id   IS NOT NULL;
            CREATE INDEX credit_notes_invoice_idx ON credit_notes (invoice_id) WHERE invoice_id IS NOT NULL;
            CREATE INDEX credit_notes_rma_idx     ON credit_notes (rma_id)     WHERE rma_id     IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS credit_notes;');
    }
};
