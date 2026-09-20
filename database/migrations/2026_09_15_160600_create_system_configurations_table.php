<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §2.7 — system_configurations.
 *
 * `location_id` is intentionally NOT a foreign key yet: `locations`
 * belongs to the Inventory context (Doc 02 §7) and is out of scope for
 * this migration set. The doc itself notes this table's FKs may be added
 * once their target tables exist (Appendix A, migration-ordering note
 * under step 4) — the column, type, CHECK and UNIQUE NULLS NOT DISTINCT
 * shape match the doc exactly regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE system_configurations (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              config_key         text        NOT NULL,
              scope              text        NOT NULL DEFAULT 'global',
              location_id        bigint,
              company_id         bigint      REFERENCES companies (id),
              value_type         text        NOT NULL,
              value_int          bigint,
              value_text         text,
              value_json         jsonb,
              description        text,
              updated_by_user_id bigint      REFERENCES users (id),
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT system_configurations_scope_chk
                CHECK (scope IN ('global','location','company')),
              CONSTRAINT system_configurations_type_chk
                CHECK (value_type IN ('int','bp','money_minor','text','bool','json','date')),
              CONSTRAINT system_configurations_coherence_chk CHECK (
                  (scope = 'global'   AND location_id IS NULL AND company_id IS NULL)
               OR (scope = 'location' AND location_id IS NOT NULL AND company_id IS NULL)
               OR (scope = 'company'  AND company_id IS NOT NULL)
              ),
              CONSTRAINT system_configurations_key_scope_uq
                UNIQUE NULLS NOT DISTINCT (config_key, scope, location_id, company_id)
            );

            COMMENT ON COLUMN system_configurations.location_id IS
              'FK to locations(id) pending — table scaffolded with the Inventory context (Doc 02 §7).';

            CREATE INDEX system_configurations_resolve_idx
              ON system_configurations (config_key, scope)
              INCLUDE (value_int, value_text, company_id, location_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_configurations CASCADE');
    }
};
