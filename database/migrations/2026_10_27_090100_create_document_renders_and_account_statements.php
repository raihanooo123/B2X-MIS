<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Signed-off 02 §31.2 only (05.17): archived document renders, account
 * statements, the statement and shipment attachment types and the trade
 * history cursor indexes.
 *
 * §31.2 states that rendered payloads, ready attachments and statements are
 * immutable while operational status may change; the two guard triggers
 * enforce exactly that in the database, as `stock_movements` and the B2B
 * ledgers are enforced (02 §31.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE document_renders (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id text NOT NULL UNIQUE,
  company_id bigint REFERENCES companies(id),
  document_type text NOT NULL CHECK (document_type IN
    ('invoice','credit_note','statement','quote','blind_packing_slip')),
  source_id bigint NOT NULL,
  version integer NOT NULL DEFAULT 1 CHECK (version > 0),
  template_version text NOT NULL,
  payload jsonb NOT NULL,
  payload_sha256 char(64) NOT NULL,
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','rendering','ready','failed')),
  attachment_id bigint REFERENCES attachments(id),
  requested_by_user_id bigint REFERENCES users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  rendered_at timestamptz,
  error_code text,
  UNIQUE(document_type, source_id, version),
  CHECK (status <> 'ready' OR (attachment_id IS NOT NULL AND rendered_at IS NOT NULL))
);
CREATE INDEX document_renders_company_idx
  ON document_renders(company_id, created_at DESC, id DESC);
CREATE INDEX document_renders_pending_idx ON document_renders(created_at, id)
  WHERE status IN ('pending','failed');

CREATE TABLE account_statements (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id text NOT NULL UNIQUE,
  company_id bigint NOT NULL REFERENCES companies(id),
  from_on date NOT NULL,
  to_on date NOT NULL,
  cutoff_at timestamptz NOT NULL,
  requested_by_user_id bigint NOT NULL REFERENCES users(id),
  requested_at timestamptz NOT NULL DEFAULT now(),
  CHECK (to_on >= from_on)
);
CREATE INDEX account_statements_company_idx
  ON account_statements(company_id, requested_at DESC, id DESC);

CREATE INDEX orders_trade_history_cursor_idx
  ON orders(company_id, placed_at DESC, id DESC) WHERE company_id IS NOT NULL;
CREATE INDEX invoices_trade_history_cursor_idx
  ON invoices(company_id, issued_at DESC, id DESC) WHERE company_id IS NOT NULL;
CREATE INDEX credit_notes_trade_history_cursor_idx
  ON credit_notes(company_id, issued_at DESC, id DESC) WHERE company_id IS NOT NULL;

ALTER TABLE attachments DROP CONSTRAINT attachments_attachable_type_chk;
ALTER TABLE attachments ADD CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
  ('b2b_application','rma','purchase_order','container','product','sku','invoice',
   'quote','credit_note','statement','shipment')) NOT VALID;
ALTER TABLE attachments VALIDATE CONSTRAINT attachments_attachable_type_chk;

-- A render's identity and payload never change; once ready, neither does
-- its archive. Only pending/rendering/failed status, error_code and the
-- first attachment/rendered_at may be written. A ready render is kept.
CREATE OR REPLACE FUNCTION document_renders_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status = 'ready' THEN
      RAISE EXCEPTION 'A ready document render is kept' USING ERRCODE = '55000';
    END IF;
    RETURN OLD;
  END IF;
  IF NEW.public_id IS DISTINCT FROM OLD.public_id
     OR NEW.company_id IS DISTINCT FROM OLD.company_id
     OR NEW.document_type IS DISTINCT FROM OLD.document_type
     OR NEW.source_id IS DISTINCT FROM OLD.source_id
     OR NEW.version IS DISTINCT FROM OLD.version
     OR NEW.template_version IS DISTINCT FROM OLD.template_version
     OR NEW.payload IS DISTINCT FROM OLD.payload
     OR NEW.payload_sha256 IS DISTINCT FROM OLD.payload_sha256
     OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'A document render payload is immutable' USING ERRCODE = '55000';
  END IF;
  IF OLD.status = 'ready' AND (NEW.status <> 'ready'
     OR NEW.attachment_id IS DISTINCT FROM OLD.attachment_id
     OR NEW.rendered_at IS DISTINCT FROM OLD.rendered_at) THEN
    RAISE EXCEPTION 'A ready document render is immutable' USING ERRCODE = '55000';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER document_renders_immutable
  BEFORE UPDATE OR DELETE ON document_renders
  FOR EACH ROW EXECUTE FUNCTION document_renders_guard();

-- A statement is a fixed as-of export: never edited or removed.
CREATE OR REPLACE FUNCTION account_statements_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'An account statement is immutable' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER account_statements_immutable
  BEFORE UPDATE OR DELETE ON account_statements
  FOR EACH ROW EXECUTE FUNCTION account_statements_guard();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE account_statements;
DROP TABLE document_renders;
DROP FUNCTION IF EXISTS account_statements_guard();
DROP FUNCTION IF EXISTS document_renders_guard();
DROP INDEX credit_notes_trade_history_cursor_idx;
DROP INDEX invoices_trade_history_cursor_idx;
DROP INDEX orders_trade_history_cursor_idx;
ALTER TABLE attachments DROP CONSTRAINT attachments_attachable_type_chk;
ALTER TABLE attachments ADD CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
  ('b2b_application','rma','purchase_order','container','product','sku','invoice','quote','credit_note')) NOT VALID;
ALTER TABLE attachments VALIDATE CONSTRAINT attachments_attachable_type_chk;
SQL);
    }
};
