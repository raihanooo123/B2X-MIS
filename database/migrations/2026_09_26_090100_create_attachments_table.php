<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §14.2 (signed off 2026-09-20) — attachments. Generic polymorphic file
 * attachment (attachable_type/attachable_id), shape follows media (§5.8).
 * `path` is a storage-driver key, never a public URL (07-nfr.md §6.3) — never
 * expose it directly; serve via a signed URL minted per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attachments (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id           text        NOT NULL,
              attachable_type     text        NOT NULL,
              attachable_id       bigint      NOT NULL,
              disk                text        NOT NULL DEFAULT 's3',
              path                text        NOT NULL,
              original_name       text,
              mime_type           text        NOT NULL,
              size_bytes          integer     NOT NULL,
              is_customer_visible boolean     NOT NULL DEFAULT false,
              uploaded_by_user_id bigint      REFERENCES users (id),
              created_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz,

              CONSTRAINT attachments_public_id_uq UNIQUE (public_id),
              CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
                ('b2b_application','rma','purchase_order','container','product','sku'))
            );

            CREATE INDEX attachments_attachable_idx ON attachments (attachable_type, attachable_id)
              WHERE deleted_at IS NULL;
            CREATE INDEX attachments_uploader_idx ON attachments (uploaded_by_user_id)
              WHERE uploaded_by_user_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS attachments CASCADE');
    }
};
