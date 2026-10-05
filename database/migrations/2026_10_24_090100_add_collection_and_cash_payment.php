<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE collection_slots (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              location_id bigint NOT NULL REFERENCES locations (id),
              slot_date date NOT NULL,
              start_time time NOT NULL,
              end_time time NOT NULL,
              capacity smallint NOT NULL DEFAULT 1,
              booked_count smallint NOT NULL DEFAULT 0,
              status text NOT NULL DEFAULT 'open',
              note text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT collection_slots_slot_uq UNIQUE (location_id, slot_date, start_time),
              CONSTRAINT collection_slots_status_chk CHECK (status IN ('open','full','closed')),
              CONSTRAINT collection_slots_times_chk CHECK (end_time > start_time),
              CONSTRAINT collection_slots_capacity_chk CHECK (booked_count <= capacity),
              CONSTRAINT collection_slots_count_chk CHECK (booked_count >= 0)
            );
            CREATE INDEX collection_slots_available_idx
              ON collection_slots (location_id, slot_date, start_time)
              INCLUDE (capacity, booked_count) WHERE status = 'open';

            CREATE TABLE collection_bookings (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              collection_slot_id bigint NOT NULL REFERENCES collection_slots (id),
              order_id bigint NOT NULL REFERENCES orders (id),
              company_id bigint REFERENCES companies (id),
              status text NOT NULL DEFAULT 'booked',
              collector_name text,
              vehicle_registration text,
              collected_at timestamptz,
              payment_due_by timestamptz,
              handed_over_by_user_id bigint REFERENCES users (id),
              no_show_at timestamptz,
              reschedule_count smallint NOT NULL DEFAULT 0,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT collection_bookings_order_uq UNIQUE (order_id),
              CONSTRAINT collection_bookings_status_chk CHECK (status IN ('booked','collected','no_show','cancelled')),
              CONSTRAINT collection_bookings_collected_chk CHECK (
                status <> 'collected' OR (collected_at IS NOT NULL AND handed_over_by_user_id IS NOT NULL)),
              CONSTRAINT collection_bookings_no_show_chk CHECK ((status = 'no_show') = (no_show_at IS NOT NULL)),
              CONSTRAINT collection_bookings_reschedule_chk CHECK (reschedule_count >= 0)
            );
            CREATE INDEX collection_bookings_slot_idx ON collection_bookings (collection_slot_id)
              WHERE status = 'booked';
            CREATE INDEX collection_bookings_company_idx ON collection_bookings (company_id, created_at DESC);
            CREATE INDEX collection_bookings_payment_due_idx ON collection_bookings (payment_due_by)
              WHERE status = 'booked' AND payment_due_by IS NOT NULL;

            ALTER TABLE orders DROP CONSTRAINT orders_payment_method_chk;
            ALTER TABLE orders ADD CONSTRAINT orders_payment_method_chk CHECK (
              payment_method IS NULL OR payment_method IN ('card','bacs','on_account','prepay','cash_at_collection'));
            ALTER TABLE orders ADD CONSTRAINT orders_cash_at_collection_chk CHECK (
              payment_method IS DISTINCT FROM 'cash_at_collection'
              OR (fulfilment_type = 'collection' AND guest_email IS NULL
                  AND (company_id IS NOT NULL OR user_id IS NOT NULL)));

            ALTER TABLE payments ADD COLUMN recorded_by_user_id bigint REFERENCES users (id);
            ALTER TABLE payments ADD CONSTRAINT payments_cash_chk CHECK (
              gateway <> 'cash'
              OR (recorded_by_user_id IS NOT NULL AND order_id IS NOT NULL AND gateway_reference IS NULL));
            CREATE UNIQUE INDEX payments_cash_order_uq ON payments (order_id)
              WHERE gateway = 'cash' AND type = 'payment' AND status = 'captured';

            CREATE TABLE pay_at_collection_suspensions (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              user_id bigint REFERENCES users (id),
              company_id bigint REFERENCES companies (id),
              reason text NOT NULL,
              no_show_count smallint,
              suspended_at timestamptz NOT NULL DEFAULT now(),
              suspended_by_user_id bigint REFERENCES users (id),
              note text,
              lifted_at timestamptz,
              lifted_by_user_id bigint REFERENCES users (id),
              lift_reason text,
              CONSTRAINT pay_at_collection_suspensions_owner_chk CHECK (
                (user_id IS NOT NULL AND company_id IS NULL) OR (user_id IS NULL AND company_id IS NOT NULL)),
              CONSTRAINT pay_at_collection_suspensions_reason_chk CHECK (reason IN ('no_shows','manual')),
              CONSTRAINT pay_at_collection_suspensions_manual_chk CHECK (
                reason <> 'manual' OR (suspended_by_user_id IS NOT NULL AND note IS NOT NULL)),
              CONSTRAINT pay_at_collection_suspensions_auto_chk CHECK (
                reason <> 'no_shows' OR no_show_count IS NOT NULL),
              CONSTRAINT pay_at_collection_suspensions_lift_chk CHECK (
                lifted_at IS NULL OR (lifted_by_user_id IS NOT NULL AND btrim(lift_reason) <> ''))
            );
            CREATE UNIQUE INDEX pay_at_collection_suspensions_user_active_uq
              ON pay_at_collection_suspensions (user_id) WHERE user_id IS NOT NULL AND lifted_at IS NULL;
            CREATE UNIQUE INDEX pay_at_collection_suspensions_company_active_uq
              ON pay_at_collection_suspensions (company_id) WHERE company_id IS NOT NULL AND lifted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE pay_at_collection_suspensions;
            DROP INDEX payments_cash_order_uq;
            ALTER TABLE payments DROP CONSTRAINT payments_cash_chk;
            ALTER TABLE payments DROP COLUMN recorded_by_user_id;
            ALTER TABLE orders DROP CONSTRAINT orders_cash_at_collection_chk;
            ALTER TABLE orders DROP CONSTRAINT orders_payment_method_chk;
            ALTER TABLE orders ADD CONSTRAINT orders_payment_method_chk CHECK (
              payment_method IS NULL OR payment_method IN ('card','bacs','on_account','prepay'));
            DROP TABLE collection_bookings;
            DROP TABLE collection_slots;
        SQL);
    }
};
