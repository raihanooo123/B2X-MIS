<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Signed-off 02 §31.3 only (05.1 §14, module 3): the order-pad tools.
 * `saved_lists`/`saved_list_lines` are absent from migrations, so their
 * 02 §14.4 definitions are adopted as written (§31 intro), then §31.3's
 * version column is added exactly as that section states it. The import
 * staging table and the two cursor/expiry indexes follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE saved_lists (
  id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id         text        NOT NULL,
  company_id        bigint      NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
  name              text        NOT NULL,
  created_by_user_id bigint     REFERENCES users (id),
  source            text        NOT NULL DEFAULT 'manual',
  source_order_id   bigint      REFERENCES orders (id),
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT saved_lists_public_id_uq UNIQUE (public_id),
  CONSTRAINT saved_lists_source_chk CHECK (source IN ('manual','cart','order'))
);

CREATE INDEX saved_lists_company_name_idx ON saved_lists (company_id, name);

CREATE TABLE saved_list_lines (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  saved_list_id    bigint      NOT NULL REFERENCES saved_lists (id) ON DELETE CASCADE,
  sku_id           bigint      NOT NULL REFERENCES skus (id),
  pack_id          bigint      NOT NULL REFERENCES packs (id),
  pack_qty         integer     NOT NULL,
  pack_base_units  integer     NOT NULL,
  base_qty         integer     NOT NULL,
  position         smallint    NOT NULL DEFAULT 0,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT saved_list_lines_list_sku_pack_uq UNIQUE (saved_list_id, sku_id, pack_id),
  CONSTRAINT saved_list_lines_qty_pos_chk CHECK (pack_qty > 0 AND pack_base_units > 0),
  CONSTRAINT saved_list_lines_base_qty_chk CHECK (base_qty = pack_qty * pack_base_units)
);

CREATE INDEX saved_list_lines_sku_idx ON saved_list_lines (sku_id);

ALTER TABLE saved_lists ADD COLUMN version bigint NOT NULL DEFAULT 0;
ALTER TABLE saved_lists ADD CONSTRAINT saved_lists_version_nonnegative_chk CHECK (version >= 0) NOT VALID;
ALTER TABLE saved_lists VALIDATE CONSTRAINT saved_lists_version_nonnegative_chk;

CREATE TABLE bulk_entry_imports (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id text NOT NULL UNIQUE,
  company_id bigint NOT NULL REFERENCES companies(id),
  user_id bigint NOT NULL REFERENCES users(id),
  tool text NOT NULL CHECK (tool IN ('order_pad','dropship')),
  source text NOT NULL CHECK (source IN ('paste','csv','saved_list','reorder')),
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','processing','ready','failed','confirmed','expired')),
  version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
  input_sha256 char(64) NOT NULL,
  private_storage_path text,
  rows jsonb NOT NULL DEFAULT '[]'::jsonb,
  confirmed_result jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  confirmed_at timestamptz,
  CHECK (expires_at > created_at),
  CHECK (jsonb_typeof(rows) = 'array')
);
CREATE INDEX saved_lists_updated_cursor_idx ON saved_lists(company_id, updated_at DESC, id DESC);
CREATE INDEX bulk_entry_imports_owner_idx
  ON bulk_entry_imports(company_id, user_id, created_at DESC, id DESC);
CREATE INDEX bulk_entry_imports_expiry_idx ON bulk_entry_imports(expires_at, id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE bulk_entry_imports;
DROP TABLE saved_list_lines;
DROP TABLE saved_lists;
SQL);
    }
};
