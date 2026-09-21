<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 05.2 §7.1 — credit_holds. Mirrors inventory (04 §2.3): an order
 * holds credit the way it allocates stock, and an invoice consumes it
 * the way a dispatch consumes stock. `credit_holds_order_uq` makes the
 * hold idempotent — a retried checkout cannot double-hold credit, the
 * same guarantee `stock_allocations_identity_uq` gives stock (02 §7.4).
 *
 * `companies.credit_held_minor` (05.2 §7.2's amendment to Doc 02 §4.3)
 * already exists on the `companies` table as of the migration that
 * created it — nothing further to add there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE credit_holds (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              company_id   bigint      NOT NULL REFERENCES companies (id),
              order_id     bigint      NOT NULL REFERENCES orders (id),
              amount_minor bigint      NOT NULL,
              status       text        NOT NULL DEFAULT 'held',
              held_at      timestamptz NOT NULL DEFAULT now(),
              released_at  timestamptz,
              invoice_id   bigint      REFERENCES invoices (id),

              CONSTRAINT credit_holds_order_uq UNIQUE (order_id),
              CONSTRAINT credit_holds_status_chk
                CHECK (status IN ('held','invoiced','released')),
              CONSTRAINT credit_holds_amount_chk CHECK (amount_minor > 0)
            );

            -- partial: the credit check only ever sums live holds
            CREATE INDEX credit_holds_company_held_idx
              ON credit_holds (company_id) INCLUDE (amount_minor) WHERE status = 'held';
            CREATE INDEX credit_holds_reaper_idx
              ON credit_holds (held_at) WHERE status = 'held';
            CREATE INDEX credit_holds_invoice_idx
              ON credit_holds (invoice_id) WHERE invoice_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS credit_holds CASCADE');
    }
};
