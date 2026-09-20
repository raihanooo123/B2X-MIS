<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.2 — orders.
 *
 * `delivery_zone_id` is intentionally NOT a foreign key yet: `delivery_zones`
 * has no full DDL in Doc 02 — it is one of the §8.4 "keys fixed now, full
 * DDL in module spec" tables (05.6 §4-5), which doesn't exist in /docs yet.
 * `quote_id` and (on order_lines) `price_list_item_id` are plain bigint
 * columns with no REFERENCES clause in the doc itself — not a gap, that's
 * how the doc specifies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE orders (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id           text        NOT NULL,
              order_number        text        NOT NULL,
              company_id          bigint      REFERENCES companies (id),
              user_id             bigint      REFERENCES users (id),
              placed_by_user_id   bigint      REFERENCES users (id),
              sales_rep_user_id   bigint      REFERENCES users (id),
              quote_id            bigint,
              channel             text        NOT NULL DEFAULT 'web',
              status              text        NOT NULL DEFAULT 'draft',
              payment_status      text        NOT NULL DEFAULT 'unpaid',
              fulfilment_type     text        NOT NULL DEFAULT 'delivery',
              currency            char(3)     NOT NULL DEFAULT 'GBP',
              subtotal_net_minor  bigint      NOT NULL DEFAULT 0,
              discount_net_minor  bigint      NOT NULL DEFAULT 0,
              shipping_net_minor  bigint      NOT NULL DEFAULT 0,
              tax_minor           bigint      NOT NULL DEFAULT 0,
              total_gross_minor   bigint      NOT NULL DEFAULT 0,
              total_cost_minor    bigint      NOT NULL DEFAULT 0,
              spend_break_id      bigint      REFERENCES order_spend_breaks (id),
              spend_break_discount_minor bigint NOT NULL DEFAULT 0,
              account_credit_applied_minor bigint NOT NULL DEFAULT 0,
              price_tier_snapshot text,
              customer_reference  text,
              delivery_zone_id    bigint,
              required_by_date    date,
              placed_at           timestamptz,
              confirmed_at        timestamptz,
              dispatched_at       timestamptz,
              cancelled_at        timestamptz,
              cancellation_fee_minor bigint   NOT NULL DEFAULT 0,
              xero_invoice_id     uuid,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT orders_number_uq    UNIQUE (order_number),
              CONSTRAINT orders_public_id_uq UNIQUE (public_id),
              CONSTRAINT orders_channel_chk CHECK (channel IN
                ('web','order_pad','phone','rep','api','dropship')),
              CONSTRAINT orders_status_chk CHECK (status IN
                ('draft','awaiting_approval','pending_payment','confirmed','picking',
                 'part_dispatched','dispatched','completed','cancelled')),
              CONSTRAINT orders_payment_status_chk CHECK (payment_status IN
                ('unpaid','deposit_paid','paid','part_refunded','refunded','on_account')),
              CONSTRAINT orders_fulfilment_chk CHECK (fulfilment_type IN
                ('delivery','collection','dropship')),
              CONSTRAINT orders_totals_chk CHECK (total_gross_minor >= 0)
            );

            COMMENT ON COLUMN orders.delivery_zone_id IS
              'FK to delivery_zones(id) pending — delivery_zones has no full DDL in Doc 02 yet (key-stub only, 05.6 §4-5).';

            CREATE INDEX orders_company_placed_idx ON orders (company_id, placed_at DESC, id)
              WHERE placed_at IS NOT NULL;
            CREATE INDEX orders_open_status_idx    ON orders (status, placed_at)
              WHERE status IN ('awaiting_approval','pending_payment','confirmed',
                               'picking','part_dispatched');
            CREATE INDEX orders_rep_placed_idx     ON orders (sales_rep_user_id, placed_at DESC)
              WHERE sales_rep_user_id IS NOT NULL;
            CREATE INDEX orders_unpaid_idx         ON orders (payment_status, placed_at)
              WHERE payment_status IN ('unpaid','on_account','deposit_paid');
            CREATE INDEX orders_customer_ref_idx   ON orders (company_id, customer_reference)
              WHERE customer_reference IS NOT NULL;
            CREATE INDEX orders_placed_at_brin     ON orders USING brin (placed_at);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS orders CASCADE');
    }
};
