<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.4 — products.
 *
 * `brand_id` is intentionally NOT a foreign key yet: `brands` has no DDL
 * in Doc 02 (Appendix A names it, no CREATE TABLE body exists). A minimal
 * definition was drafted as a pending amendment at Doc 02 §5.9 and awaits
 * sign-off. The column, type and index shape here match the doc exactly;
 * the FK constraint is added once §5.9 is approved and `brands` exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE products (
              id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id           text        NOT NULL,
              product_type        text        NOT NULL DEFAULT 'simple',
              name                text        NOT NULL,
              slug                text        NOT NULL,
              brand_id            bigint,
              primary_category_id bigint      REFERENCES categories (id),
              status              text        NOT NULL DEFAULT 'draft',
              short_description   text,
              description         text,
              rrp_minor           bigint,
              origin_country      char(2),
              hs_code             text,
              is_featured         boolean     NOT NULL DEFAULT false,
              meta_title          text,
              meta_description    text,
              specifications      jsonb,
              completeness_score  smallint    NOT NULL DEFAULT 0,
              search_vector       tsvector GENERATED ALWAYS AS (
                                    setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                                    setweight(to_tsvector('english',
                                              coalesce(short_description, '')), 'B') ||
                                    setweight(to_tsvector('english', coalesce(description, '')), 'C')
                                  ) STORED,
              published_at        timestamptz,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz,

              CONSTRAINT products_type_chk CHECK (product_type IN ('simple','variant')),
              CONSTRAINT products_status_chk CHECK (status IN
                ('draft','active','coming_soon','discontinued','archived')),
              CONSTRAINT products_completeness_chk
                CHECK (completeness_score BETWEEN 0 AND 100)
            );

            COMMENT ON COLUMN products.brand_id IS
              'FK to brands(id) pending — brands has no DDL in Doc 02 yet; drafted at §5.9, awaiting sign-off.';

            CREATE UNIQUE INDEX products_slug_uq      ON products (slug) WHERE deleted_at IS NULL;
            CREATE UNIQUE INDEX products_public_id_uq ON products (public_id);

            CREATE INDEX products_active_published_idx ON products (published_at DESC, id)
              WHERE status = 'active' AND deleted_at IS NULL;
            CREATE INDEX products_cat_active_idx  ON products (primary_category_id, id)
              WHERE status = 'active' AND deleted_at IS NULL;
            CREATE INDEX products_brand_active_idx ON products (brand_id, id)
              WHERE status = 'active' AND deleted_at IS NULL;
            CREATE INDEX products_featured_idx ON products (published_at DESC)
              WHERE is_featured AND status = 'active';
            CREATE INDEX products_incomplete_idx ON products (completeness_score)
              WHERE status = 'active' AND completeness_score < 60;
            CREATE INDEX products_search_gin ON products USING gin (search_vector);
            CREATE INDEX products_name_trgm  ON products USING gin (name gin_trgm_ops);
            CREATE INDEX products_specs_gin  ON products USING gin (specifications)
              WHERE specifications IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS products CASCADE');
    }
};
