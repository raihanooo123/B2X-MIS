<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.3 — categories, plus the category_closure table that makes
 * "all active products in this category and every descendant" a single
 * equality join. `path ltree` needs the ltree extension (enabled in the
 * extensions migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE categories (
              id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              parent_id        bigint      REFERENCES categories (id),
              name             text        NOT NULL,
              slug             text        NOT NULL,
              path             ltree,
              depth            smallint    NOT NULL DEFAULT 0,
              position         smallint    NOT NULL DEFAULT 0,
              status           text        NOT NULL DEFAULT 'active',
              icon_media_id    bigint,
              meta_title       text,
              meta_description text,
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT categories_status_chk CHECK (status IN ('active','hidden','archived'))
            );

            CREATE UNIQUE INDEX categories_slug_uq ON categories (slug);
            CREATE INDEX categories_parent_pos_idx ON categories (parent_id, position);
            CREATE INDEX categories_path_gist      ON categories USING gist (path);
            CREATE INDEX categories_active_idx     ON categories (depth, position)
              WHERE status = 'active';

            CREATE TABLE category_closure (
              ancestor_id   bigint   NOT NULL REFERENCES categories (id) ON DELETE CASCADE,
              descendant_id bigint   NOT NULL REFERENCES categories (id) ON DELETE CASCADE,
              depth         smallint NOT NULL,
              PRIMARY KEY (ancestor_id, descendant_id)
            );

            CREATE INDEX category_closure_descendant_idx
              ON category_closure (descendant_id, ancestor_id) INCLUDE (depth);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS category_closure CASCADE');
        DB::statement('DROP TABLE IF EXISTS categories CASCADE');
    }
};
