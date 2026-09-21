<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §4.5 — addresses. An order never foreign-keys here: orders hold an
 * immutable snapshot in order_addresses (§8.4), so a customer editing their
 * address never retrospectively changes where a historical order was
 * delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE addresses (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              company_id       bigint      NOT NULL REFERENCES companies (id),
              label            text,
              contact_name     text,
              phone            text,
              line1            text        NOT NULL,
              line2            text,
              city             text        NOT NULL,
              county           text,
              postcode         text        NOT NULL,
              country_code     char(2)     NOT NULL DEFAULT 'GB',
              address_type     text        NOT NULL DEFAULT 'both',
              is_default       boolean     NOT NULL DEFAULT false,
              delivery_zone_id bigint      REFERENCES delivery_zones (id),
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),
              deleted_at       timestamptz,

              CONSTRAINT addresses_type_chk
                CHECK (address_type IN ('billing','delivery','both'))
            );

            CREATE INDEX addresses_company_type_idx ON addresses (company_id, address_type)
              WHERE deleted_at IS NULL;
            CREATE INDEX addresses_postcode_idx     ON addresses (postcode);
            CREATE INDEX addresses_zone_idx         ON addresses (delivery_zone_id)
              WHERE delivery_zone_id IS NOT NULL;
            CREATE UNIQUE INDEX addresses_default_uq
              ON addresses (company_id, address_type)
              WHERE is_default AND deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS addresses CASCADE');
    }
};
