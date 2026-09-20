<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.5.4 (signed off 2026-09-20) — payment_allocations. Cash
 * application: which specific invoice(s) a payment settles, now that one
 * order can carry several invoices (per-shipment invoicing, 05.5 §7.3).
 * Append-only by design — no UNIQUE(payment_id, invoice_id) — mirroring the
 * ledger discipline of stock_movements/account_credit_movements/payments.
 * amount_minor is signed: positive for an application, negative for a
 * refund or reallocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_allocations (
              id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              payment_id           bigint      NOT NULL REFERENCES payments (id),
              invoice_id           bigint      NOT NULL REFERENCES invoices (id),
              amount_minor         bigint      NOT NULL,
              allocation_reference text,
              reason_code          text,
              actor_user_id        bigint      REFERENCES users (id),
              allocated_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT payment_allocations_amount_chk CHECK (amount_minor <> 0),
              CONSTRAINT payment_allocations_reason_chk CHECK (
                amount_minor > 0 OR reason_code IS NOT NULL
              )
            );

            CREATE UNIQUE INDEX payment_allocations_reference_uq
              ON payment_allocations (allocation_reference) WHERE allocation_reference IS NOT NULL;
            CREATE INDEX payment_allocations_payment_idx ON payment_allocations (payment_id)
              INCLUDE (amount_minor);
            CREATE INDEX payment_allocations_invoice_idx ON payment_allocations (invoice_id)
              INCLUDE (payment_id, amount_minor);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS payment_allocations CASCADE');
    }
};
