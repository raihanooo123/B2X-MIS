<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.5 — skus, the central stockable/priceable/sellable entity.
 *
 * `default_pack_id` has no FK here — it is circular with `packs`
 * (skus -> packs -> skus) and is added as a DEFERRABLE INITIALLY DEFERRED
 * constraint in the migration immediately after packs is created, exactly
 * as the doc specifies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE skus (
              id                       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id                text        NOT NULL,
              product_id               bigint      NOT NULL REFERENCES products (id),
              sku_code                 text        NOT NULL,
              barcode_ean              text,
              supplier_ref             text,
              variant_label            text,
              status                   text        NOT NULL DEFAULT 'draft',
              tax_class_id             bigint      NOT NULL REFERENCES tax_classes (id),
              base_unit                text        NOT NULL DEFAULT 'each',
              unit_weight_g            integer,
              moq_base_qty             integer     NOT NULL DEFAULT 1,
              order_increment_base_qty integer     NOT NULL DEFAULT 1,
              max_order_base_qty       integer,
              default_pack_id          bigint,
              is_stock_tracked         boolean     NOT NULL DEFAULT true,
              tracking_mode            text        NOT NULL DEFAULT 'none',
              allocation_strategy      text        NOT NULL DEFAULT 'none',
              requires_expiry          boolean     NOT NULL DEFAULT false,
              shelf_life_days          smallint,
              min_remaining_shelf_life_days smallint,
              allow_backorder          boolean     NOT NULL DEFAULT false,
              is_refundable            boolean     NOT NULL DEFAULT true,
              non_refundable_reason    text,
              position                 smallint    NOT NULL DEFAULT 0,
              created_at               timestamptz NOT NULL DEFAULT now(),
              updated_at               timestamptz NOT NULL DEFAULT now(),
              deleted_at               timestamptz,

              CONSTRAINT skus_status_chk CHECK (status IN
                ('draft','active','coming_soon','discontinued','archived')),
              CONSTRAINT skus_base_unit_chk CHECK (base_unit IN
                ('each','kg','litre','metre','pair')),
              CONSTRAINT skus_tracking_mode_chk CHECK (tracking_mode IN
                ('none','batch','serial','batch_and_serial')),
              CONSTRAINT skus_strategy_chk CHECK (allocation_strategy IN
                ('none','fifo','fefo','lifo')),
              CONSTRAINT skus_nonref_reason_chk CHECK (non_refundable_reason IS NULL
                OR non_refundable_reason IN ('consumable','hygiene','electrical_sealed','bespoke')),
              CONSTRAINT skus_increment_chk CHECK (order_increment_base_qty >= 1),
              CONSTRAINT skus_moq_chk       CHECK (moq_base_qty >= 1),
              CONSTRAINT skus_max_chk       CHECK (max_order_base_qty IS NULL
                                                   OR max_order_base_qty >= moq_base_qty),
              CONSTRAINT skus_nonref_chk    CHECK ((is_refundable AND non_refundable_reason IS NULL)
                                                OR (NOT is_refundable
                                                    AND non_refundable_reason IS NOT NULL)),
              CONSTRAINT skus_tracking_chk  CHECK (tracking_mode = 'none' OR is_stock_tracked),
              CONSTRAINT skus_expiry_chk    CHECK (NOT requires_expiry
                                                   OR tracking_mode IN ('batch','batch_and_serial')),
              CONSTRAINT skus_fefo_chk      CHECK (allocation_strategy <> 'fefo'
                                                   OR tracking_mode IN ('batch','batch_and_serial'))
            );

            CREATE UNIQUE INDEX skus_sku_code_uq  ON skus (sku_code)    WHERE deleted_at IS NULL;
            CREATE UNIQUE INDEX skus_public_id_uq ON skus (public_id);
            CREATE UNIQUE INDEX skus_barcode_uq   ON skus (barcode_ean)
              WHERE barcode_ean IS NOT NULL AND deleted_at IS NULL;
            CREATE INDEX skus_product_position_idx ON skus (product_id, position, id)
              WHERE deleted_at IS NULL;
            CREATE INDEX skus_active_idx     ON skus (id) WHERE status = 'active';
            CREATE INDEX skus_supplier_ref_idx ON skus (supplier_ref) WHERE supplier_ref IS NOT NULL;
            CREATE INDEX skus_tracked_idx    ON skus (tracking_mode, id)
              WHERE tracking_mode <> 'none';
            CREATE INDEX skus_code_trgm_idx  ON skus USING gin (sku_code gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS skus CASCADE');
    }
};
