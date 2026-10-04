<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 02 §28: one exclusive owner, immutable order snapshots, public history. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE addresses ALTER COLUMN company_id DROP NOT NULL');
        DB::statement('ALTER TABLE addresses ADD COLUMN user_id bigint REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE addresses ADD COLUMN public_id text');
        DB::statement('ALTER TABLE addresses ADD CONSTRAINT addresses_owner_chk CHECK ((company_id IS NOT NULL AND user_id IS NULL) OR (company_id IS NULL AND user_id IS NOT NULL)) NOT VALID');
        DB::statement('ALTER TABLE addresses VALIDATE CONSTRAINT addresses_owner_chk');
        DB::statement("ALTER TABLE addresses ADD CONSTRAINT addresses_public_delivery_chk CHECK (user_id IS NULL OR (address_type = 'delivery' AND country_code = 'GB' AND contact_name IS NOT NULL AND btrim(contact_name) <> '')) NOT VALID");
        DB::statement('ALTER TABLE addresses VALIDATE CONSTRAINT addresses_public_delivery_chk');
        DB::statement('ALTER TABLE addresses ADD CONSTRAINT addresses_public_id_uq UNIQUE (public_id)');

        // Query builder deliberately includes soft-deleted addresses.
        DB::table('addresses')->select('id')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('addresses')->where('id', $row->id)->update(['public_id' => (string) Str::ulid()]);
            }
        });
        DB::statement('ALTER TABLE addresses ALTER COLUMN public_id SET NOT NULL');
        DB::statement('CREATE INDEX addresses_user_live_idx ON addresses (user_id, created_at, id) WHERE user_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX addresses_user_default_uq ON addresses (user_id) WHERE user_id IS NOT NULL AND is_default AND deleted_at IS NULL');
        DB::statement('CREATE INDEX orders_public_user_placed_idx ON orders (user_id, placed_at DESC, id DESC) WHERE company_id IS NULL AND placed_at IS NOT NULL');
    }

    public function down(): void
    {
        // Never silently discard a public customer's saved addresses.
        if (DB::table('addresses')->whereNotNull('user_id')->exists()) {
            throw new RuntimeException('Remove user-owned addresses before rolling back public address ownership.');
        }
        DB::statement('DROP INDEX orders_public_user_placed_idx');
        DB::statement('DROP INDEX addresses_user_default_uq');
        DB::statement('DROP INDEX addresses_user_live_idx');
        DB::statement('ALTER TABLE addresses DROP CONSTRAINT addresses_public_delivery_chk, DROP CONSTRAINT addresses_owner_chk, DROP CONSTRAINT addresses_public_id_uq');
        DB::statement('ALTER TABLE addresses DROP COLUMN public_id, DROP COLUMN user_id');
        DB::statement('ALTER TABLE addresses ALTER COLUMN company_id SET NOT NULL');
    }
};
