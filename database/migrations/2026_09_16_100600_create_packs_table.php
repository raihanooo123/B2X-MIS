<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.6 — packs. Pack structure as first-class data: a pack is a
 * transaction unit, never a storage unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE packs (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              sku_id            bigint      NOT NULL REFERENCES skus (id) ON DELETE CASCADE,
              code              text        NOT NULL,
              label             text        NOT NULL,
              pack_level        text        NOT NULL DEFAULT 'each',
              base_units        integer     NOT NULL,
              barcode           text,
              gross_weight_g    integer,
              length_mm         integer,
              width_mm          integer,
              height_mm         integer,
              packs_per_layer   smallint,
              layers_per_pallet smallint,
              is_sellable       boolean     NOT NULL DEFAULT true,
              is_default_sell   boolean     NOT NULL DEFAULT false,
              position          smallint    NOT NULL DEFAULT 0,
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT packs_level_chk CHECK (pack_level IN ('each','inner','outer','pallet')),
              CONSTRAINT packs_units_chk CHECK (base_units >= 1),
              CONSTRAINT packs_sku_code_uq  UNIQUE (sku_id, code),
              CONSTRAINT packs_sku_units_uq UNIQUE (sku_id, base_units)
            );

            CREATE UNIQUE INDEX packs_default_sell_uq ON packs (sku_id) WHERE is_default_sell;
            CREATE UNIQUE INDEX packs_barcode_uq      ON packs (barcode) WHERE barcode IS NOT NULL;
            CREATE INDEX packs_sku_sellable_idx ON packs (sku_id, base_units)
              INCLUDE (label, gross_weight_g) WHERE is_sellable;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS packs CASCADE');
    }
};
