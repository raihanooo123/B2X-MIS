<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §11.3 — Number sequences.
 *
 * Gapless document numbering (order_number, invoice_number, rma_number,
 * credit_note_number, quote_number, po_number). Postgres sequences are
 * non-transactional and gap on rollback, which is unacceptable for a
 * document series accounting must be able to inspect. Rows here are
 * incremented with `SELECT ... FOR UPDATE` inside the same transaction
 * that creates the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE number_sequences (
              key_name   text     PRIMARY KEY,
              prefix     text     NOT NULL DEFAULT '',
              next_value bigint   NOT NULL DEFAULT 1,
              padding    smallint NOT NULL DEFAULT 6
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS number_sequences CASCADE');
    }
};
