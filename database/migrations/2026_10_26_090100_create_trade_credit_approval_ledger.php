<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Signed-off 02 §31.1 only. Other B2B schema is delivered with its own module. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_b2b_history_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'B2B history is append-only' USING ERRCODE = '55000';
END;
$$;
ALTER TABLE company_users ADD CONSTRAINT company_users_order_limit_nonnegative_chk
  CHECK (order_limit_minor IS NULL OR order_limit_minor >= 0) NOT VALID;
ALTER TABLE company_users VALIDATE CONSTRAINT company_users_order_limit_nonnegative_chk;
CREATE TABLE order_approval_requests (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id text NOT NULL UNIQUE,
  company_id bigint NOT NULL REFERENCES companies(id),
  order_id bigint NOT NULL REFERENCES orders(id),
  requested_by_user_id bigint NOT NULL REFERENCES users(id),
  approval_kind text NOT NULL CHECK (approval_kind IN ('buyer_limit','credit_exception')),
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','approved','rejected','expired')),
  order_gross_minor bigint NOT NULL CHECK (order_gross_minor >= 0),
  requested_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  decided_at timestamptz,
  decided_by_user_id bigint REFERENCES users(id),
  decision_reason text,
  UNIQUE(order_id, approval_kind),
  CHECK (expires_at > requested_at),
  CHECK ((status = 'pending' AND decided_at IS NULL)
      OR (status <> 'pending' AND decided_at IS NOT NULL))
);
CREATE INDEX order_approvals_company_queue_idx
  ON order_approval_requests(company_id, status, requested_at DESC, id DESC);
CREATE INDEX order_approvals_expiry_idx ON order_approval_requests(expires_at, id)
  WHERE status = 'pending';
ALTER TABLE invoices ADD COLUMN credited_minor bigint NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD CONSTRAINT invoices_credited_nonnegative_chk
  CHECK (credited_minor >= 0) NOT VALID;
ALTER TABLE invoices VALIDATE CONSTRAINT invoices_credited_nonnegative_chk;
CREATE INDEX invoices_credit_unpaid_idx ON invoices(company_id, due_at, id)
  INCLUDE(total_gross_minor, paid_minor, credited_minor)
  WHERE status IN ('issued','part_paid','overdue');
CREATE TABLE account_credit_events (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_key text NOT NULL UNIQUE,
  company_id bigint NOT NULL REFERENCES companies(id),
  event_kind text NOT NULL,
  payload_hash char(64) NOT NULL,
  occurred_at timestamptz NOT NULL DEFAULT now(),
  actor_user_id bigint REFERENCES users(id)
);
CREATE INDEX account_credit_events_company_idx
  ON account_credit_events(company_id, occurred_at, id);
CREATE TABLE credit_note_allocations (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  credit_note_id bigint NOT NULL REFERENCES credit_notes(id),
  invoice_id bigint NOT NULL REFERENCES invoices(id),
  event_id bigint NOT NULL REFERENCES account_credit_events(id),
  amount_minor bigint NOT NULL CHECK (amount_minor <> 0),
  reason_code text,
  allocated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(event_id, credit_note_id, invoice_id),
  CHECK (amount_minor > 0 OR reason_code IS NOT NULL)
);
CREATE INDEX credit_note_allocations_invoice_idx ON credit_note_allocations(invoice_id)
  INCLUDE(amount_minor);
CREATE INDEX credit_note_allocations_note_idx ON credit_note_allocations(credit_note_id)
  INCLUDE(invoice_id, amount_minor);

CREATE TABLE account_credit_movements (
  id                  bigint GENERATED ALWAYS AS IDENTITY,
  occurred_at         timestamptz NOT NULL DEFAULT now(),
  company_id          bigint      NOT NULL,
  event_id            bigint      NOT NULL,
  entry_no            smallint    NOT NULL CHECK (entry_no > 0),
  movement_type       text        NOT NULL,
  amount_minor        bigint      NOT NULL,
  balance_after_minor bigint,
  reference_type      text,
  reference_id        bigint,
  credit_note_id      bigint,
  order_id            bigint,
  payment_id          bigint,
  reason_code         text,
  note                text,
  actor_user_id       bigint,
  created_at          timestamptz NOT NULL DEFAULT now(),

  PRIMARY KEY (id, occurred_at),
  UNIQUE(event_id, entry_no, occurred_at),
  CONSTRAINT account_credit_movements_type_chk CHECK (movement_type IN
    ('credit_note','applied_to_order','refunded_to_bank','refunded_to_card',
     'adjustment','expiry','reversal','applied_to_invoice','payout_reserved')),
  CONSTRAINT account_credit_movements_reason_chk
    CHECK (movement_type <> 'adjustment' OR reason_code IS NOT NULL),
  CONSTRAINT account_credit_movements_nonzero_chk CHECK (amount_minor <> 0)
) PARTITION BY RANGE (occurred_at);

CREATE TABLE account_credit_movements_2026 PARTITION OF account_credit_movements
  FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
CREATE TABLE account_credit_movements_default PARTITION OF account_credit_movements DEFAULT;
CREATE INDEX account_credit_movements_company_idx
  ON account_credit_movements(company_id, occurred_at, id) INCLUDE(amount_minor);
CREATE INDEX account_credit_movements_reference_idx
  ON account_credit_movements(reference_type, reference_id) WHERE reference_type IS NOT NULL;
CREATE INDEX account_credit_movements_event_idx ON account_credit_movements(event_id, occurred_at);
CREATE INDEX account_credit_movements_occurred_brin
  ON account_credit_movements USING brin(occurred_at);

CREATE TABLE account_credit_payouts (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id text NOT NULL UNIQUE,
  company_id bigint NOT NULL REFERENCES companies(id),
  event_key text NOT NULL UNIQUE,
  method text NOT NULL CHECK (method IN ('bank','original_card')),
  amount_minor bigint NOT NULL CHECK (amount_minor > 0),
  source_payment_id bigint REFERENCES payments(id),
  completed_payment_id bigint REFERENCES payments(id),
  destination_reference text NOT NULL,
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','processing','paid','failed')),
  requested_by_user_id bigint NOT NULL REFERENCES users(id),
  approved_by_user_id bigint REFERENCES users(id),
  requested_at timestamptz NOT NULL DEFAULT now(),
  completed_at timestamptz,
  CHECK (method <> 'original_card' OR source_payment_id IS NOT NULL)
);
CREATE INDEX account_credit_payouts_pending_idx ON account_credit_payouts(requested_at, id)
  WHERE status IN ('pending','processing');

CREATE TRIGGER account_credit_movements_append_only BEFORE UPDATE OR DELETE ON account_credit_movements
FOR EACH ROW EXECUTE FUNCTION reject_b2b_history_mutation();
CREATE TRIGGER account_credit_movements_no_truncate BEFORE TRUNCATE ON account_credit_movements
FOR EACH STATEMENT EXECUTE FUNCTION reject_b2b_history_mutation();

CREATE TRIGGER account_credit_events_append_only BEFORE UPDATE OR DELETE ON account_credit_events
FOR EACH ROW EXECUTE FUNCTION reject_b2b_history_mutation();
CREATE TRIGGER account_credit_events_no_truncate BEFORE TRUNCATE ON account_credit_events
FOR EACH STATEMENT EXECUTE FUNCTION reject_b2b_history_mutation();

CREATE TRIGGER credit_note_allocations_append_only BEFORE UPDATE OR DELETE ON credit_note_allocations
FOR EACH ROW EXECUTE FUNCTION reject_b2b_history_mutation();
CREATE TRIGGER credit_note_allocations_no_truncate BEFORE TRUNCATE ON credit_note_allocations
FOR EACH STATEMENT EXECUTE FUNCTION reject_b2b_history_mutation();

CREATE TRIGGER account_credit_movements_2026_no_truncate BEFORE TRUNCATE ON account_credit_movements_2026 FOR EACH STATEMENT EXECUTE FUNCTION reject_b2b_history_mutation();

CREATE TRIGGER account_credit_movements_default_no_truncate BEFORE TRUNCATE ON account_credit_movements_default FOR EACH STATEMENT EXECUTE FUNCTION reject_b2b_history_mutation();

SQL);
        // Preserve an existing spendable opening balance; do not fabricate old notes.
        DB::table('companies')->where('account_balance_minor', '>', 0)->orderBy('id')
            ->eachById(function (object $company): void {
                $at = now();
                $id = DB::table('account_credit_events')->insertGetId([
                    'event_key' => 'opening-balance:'.$company->id,
                    'company_id' => $company->id,
                    'event_kind' => 'adjustment',
                    'payload_hash' => hash('sha256', (string) $company->account_balance_minor),
                    'occurred_at' => $at,
                ]);
                DB::table('account_credit_movements')->insert([
                    'event_id' => $id, 'entry_no' => 1, 'company_id' => $company->id,
                    'occurred_at' => $at, 'movement_type' => 'adjustment',
                    'amount_minor' => $company->account_balance_minor,
                    'balance_after_minor' => $company->account_balance_minor,
                    'reason_code' => 'opening_balance_migration',
                ]);
            });
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE account_credit_payouts;
DROP TABLE account_credit_movements;
DROP TABLE credit_note_allocations;
DROP TABLE account_credit_events;
DROP TABLE order_approval_requests;
DROP INDEX invoices_credit_unpaid_idx;
ALTER TABLE invoices DROP COLUMN credited_minor;
ALTER TABLE company_users DROP CONSTRAINT company_users_order_limit_nonnegative_chk;
DROP FUNCTION IF EXISTS reject_b2b_history_mutation();
SQL);
    }
};
