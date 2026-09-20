<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.5.1 (signed off 2026-09-20) — payments. Refunds are separate
 * rows (type='refund', refunded_payment_id), never a mutated amount. Card
 * data never stored beyond card_brand/card_last4 (07-nfr.md §6.4, SAQ-A
 * scope) — gateway_reference is the gateway's own token/charge id, never a
 * PAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payments (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id             text        NOT NULL,
              order_id              bigint      REFERENCES orders (id),
              company_id            bigint      NOT NULL REFERENCES companies (id),
              type                  text        NOT NULL DEFAULT 'payment',
              gateway               text        NOT NULL,
              gateway_reference     text,
              status                text        NOT NULL DEFAULT 'pending',
              amount_minor          bigint      NOT NULL,
              currency              char(3)     NOT NULL DEFAULT 'GBP',
              card_brand            text,
              card_last4            text,
              refunded_payment_id   bigint      REFERENCES payments (id),
              failure_reason        text,
              authorized_at         timestamptz,
              captured_at           timestamptz,
              created_at            timestamptz NOT NULL DEFAULT now(),
              updated_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT payments_public_id_uq UNIQUE (public_id),
              CONSTRAINT payments_type_chk CHECK (type IN ('payment','refund')),
              CONSTRAINT payments_gateway_chk CHECK (gateway IN ('stripe','bacs','cash','internal')),
              CONSTRAINT payments_status_chk CHECK (status IN
                ('pending','authorized','captured','failed','refunded','part_refunded','voided')),
              CONSTRAINT payments_amount_chk CHECK (amount_minor >= 0),
              CONSTRAINT payments_last4_chk CHECK (card_last4 IS NULL OR length(card_last4) = 4),
              CONSTRAINT payments_refund_ref_chk CHECK (
                  (type = 'payment' AND refunded_payment_id IS NULL)
               OR (type = 'refund'  AND refunded_payment_id IS NOT NULL)
              )
            );

            CREATE UNIQUE INDEX payments_gateway_reference_uq
              ON payments (gateway, gateway_reference) WHERE gateway_reference IS NOT NULL;
            CREATE INDEX payments_order_idx ON payments (order_id, created_at)
              WHERE order_id IS NOT NULL;
            CREATE INDEX payments_company_idx ON payments (company_id, created_at DESC);
            CREATE INDEX payments_pending_idx ON payments (status, created_at)
              WHERE status IN ('pending','authorized');
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS payments CASCADE');
    }
};
