<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §8.4 — order_addresses. Immutable by convention (no updated_at):
 * an order never foreign-keys to `addresses` (§4.5 invariant). For a
 * dropship order the delivery address is the end customer's, snapshotted
 * here and deliberately never written to `addresses` — 05.8 §5.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE order_addresses (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              order_id     bigint  NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
              address_type text    NOT NULL,
              contact_name text,
              phone        text,
              company_name text,
              line1        text    NOT NULL,
              line2        text,
              city         text    NOT NULL,
              county       text,
              postcode     text    NOT NULL,
              country_code char(2) NOT NULL DEFAULT 'GB',

              CONSTRAINT order_addresses_order_type_uq UNIQUE (order_id, address_type),
              CONSTRAINT order_addresses_type_chk CHECK (address_type IN ('billing','delivery'))
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS order_addresses CASCADE');
    }
};
