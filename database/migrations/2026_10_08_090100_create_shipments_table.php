<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.6 (signed off 2026-09-20) — shipments. A shipment triggers the
 * `dispatch` stock movement (04 §7.2) and is generated per shipment, not per
 * order (05.5 §5.1). No `shipment_number`: §11.3's gapless-numbering list
 * does not include one — `public_id` is the external reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipments (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id         text        NOT NULL,
              order_id          bigint      NOT NULL REFERENCES orders (id),
              location_id       bigint      NOT NULL REFERENCES locations (id),
              fulfilment_type   text        NOT NULL DEFAULT 'delivery',
              status            text        NOT NULL DEFAULT 'pending',
              carrier           text,
              tracking_number   text,
              parcel_count      integer,
              total_weight_g    integer,
              note              text,
              picked_at         timestamptz,
              packed_at         timestamptz,
              dispatched_at     timestamptz,
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT shipments_public_id_uq UNIQUE (public_id),
              CONSTRAINT shipments_fulfilment_chk CHECK (fulfilment_type IN
                ('delivery','collection','dropship')),
              CONSTRAINT shipments_status_chk CHECK (status IN
                ('pending','picking','picked','packed','dispatched','cancelled'))
            );

            CREATE INDEX shipments_order_idx ON shipments (order_id);
            CREATE INDEX shipments_tracking_idx ON shipments (tracking_number)
              WHERE tracking_number IS NOT NULL;
            CREATE INDEX shipments_dispatched_idx ON shipments (dispatched_at)
              WHERE dispatched_at IS NOT NULL;
            CREATE INDEX shipments_open_status_idx ON shipments (location_id, status)
              WHERE status IN ('pending','picking','picked','packed');
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS shipments CASCADE');
    }
};
