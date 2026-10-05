<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 05.11 §3 (signed off 2026-10-05, 02 §30) — legal and help pages. One
 * editable draft per page on `cms_pages`; every published version kept,
 * immutable, on `cms_page_versions` (the 02 §25.1 pattern). The five page
 * rows are created here, keys only: a page with no published version is a
 * 404 and has no footer link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE cms_pages (
              id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              page_key                  text        NOT NULL,
              draft_title               text,
              draft_meta_description    text,
              draft_body_markdown       text,
              draft_updated_at          timestamptz,
              draft_updated_by_user_id  bigint      REFERENCES users (id),
              created_at                timestamptz NOT NULL DEFAULT now(),
              updated_at                timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT cms_pages_key_uq  UNIQUE (page_key),
              CONSTRAINT cms_pages_key_chk CHECK (page_key IN
                ('privacy','cookies','delivery','returns','contact')),
              CONSTRAINT cms_pages_draft_meta_chk CHECK (
                draft_meta_description IS NULL OR length(draft_meta_description) <= 320)
            );

            CREATE TABLE cms_page_versions (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              cms_page_id           bigint      NOT NULL REFERENCES cms_pages (id),
              version_no            integer     NOT NULL,
              title                 text        NOT NULL,
              meta_description      text,
              body_markdown         text        NOT NULL,
              body_sha256           text        NOT NULL,
              effective_from        timestamptz NOT NULL,
              change_note           text,
              published_by_user_id  bigint      NOT NULL REFERENCES users (id),
              created_at            timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT cms_page_versions_no_uq        UNIQUE (cms_page_id, version_no),
              CONSTRAINT cms_page_versions_effective_uq UNIQUE (cms_page_id, effective_from),
              CONSTRAINT cms_page_versions_no_chk       CHECK (version_no >= 1),
              CONSTRAINT cms_page_versions_sha_chk      CHECK (body_sha256 ~ '^[0-9a-f]{64}$'),
              CONSTRAINT cms_page_versions_title_chk    CHECK (btrim(title) <> ''),
              CONSTRAINT cms_page_versions_meta_chk     CHECK (
                meta_description IS NULL OR length(meta_description) <= 320)
            );

            -- reject_row_mutation() exists (02 §25.1, 2026_10_14_090100)
            CREATE TRIGGER cms_page_versions_immutable
              BEFORE UPDATE OR DELETE ON cms_page_versions
              FOR EACH ROW EXECUTE FUNCTION reject_row_mutation();

            INSERT INTO cms_pages (page_key)
            VALUES ('privacy'), ('cookies'), ('delivery'), ('returns'), ('contact');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS cms_page_versions;
            DROP TABLE IF EXISTS cms_pages;
        SQL);
    }
};
