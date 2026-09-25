<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §22 (signed off 2026-09-25) — notifications (05.12).
 *
 *   - `notification_preferences`: marketing consent, append-only. The
 *     latest row per (user, category, channel) is current; earlier rows
 *     are the proof of consent (07 §7.2).
 *   - `notification_log`: one row per message per recipient, written
 *     before sending; `dedup_key` makes dispatch idempotent.
 *   - `companies.accounts_email`: preferred recipient for invoice messages.
 *   - `attachments`: `quote` and `credit_note` join the attachable types.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE notification_preferences (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              user_id               bigint      NOT NULL REFERENCES users (id),
              category              text        NOT NULL,
              channel               text        NOT NULL,
              opted_in              boolean     NOT NULL,
              source                text        NOT NULL,
              consent_text_version  text,
              ip                    inet,
              user_agent            text,
              actor_user_id         bigint      REFERENCES users (id),
              recorded_at           timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT notification_preferences_category_chk CHECK (category IN ('marketing')),
              CONSTRAINT notification_preferences_channel_chk  CHECK (channel IN ('email','sms')),
              CONSTRAINT notification_preferences_source_chk   CHECK (source IN
                ('registration','account_settings','unsubscribe_link','admin','erasure','complaint')),
              CONSTRAINT notification_preferences_consent_chk  CHECK (
                NOT opted_in OR consent_text_version IS NOT NULL
              )
            );

            CREATE INDEX notification_preferences_current_idx
              ON notification_preferences (user_id, category, channel, recorded_at DESC)
              INCLUDE (opted_in);

            CREATE TABLE notification_log (
              id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              notification_key     text        NOT NULL,
              category             text        NOT NULL,
              channel              text        NOT NULL,
              template_version     text        NOT NULL,
              user_id              bigint      REFERENCES users (id),
              company_id           bigint      REFERENCES companies (id),
              recipient            text        NOT NULL,
              subject_type         text,
              subject_id           bigint,
              attachment_id        bigint      REFERENCES attachments (id),
              dedup_key            text        NOT NULL,
              status               text        NOT NULL DEFAULT 'queued',
              attempts             smallint    NOT NULL DEFAULT 0,
              provider_message_id  text,
              last_error           text,
              queued_at            timestamptz NOT NULL DEFAULT now(),
              sent_at              timestamptz,
              delivered_at         timestamptz,
              failed_at            timestamptz,
              updated_at           timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT notification_log_dedup_uq UNIQUE (dedup_key),
              CONSTRAINT notification_log_category_chk CHECK (category IN ('transactional','security','marketing')),
              CONSTRAINT notification_log_channel_chk  CHECK (channel IN ('email','sms')),
              CONSTRAINT notification_log_status_chk   CHECK (status IN
                ('queued','sent','delivered','bounced','complained','failed','suppressed')),
              CONSTRAINT notification_log_subject_chk  CHECK ((subject_type IS NULL) = (subject_id IS NULL))
            );

            CREATE UNIQUE INDEX notification_log_provider_uq
              ON notification_log (channel, provider_message_id) WHERE provider_message_id IS NOT NULL;
            CREATE INDEX notification_log_subject_idx
              ON notification_log (subject_type, subject_id, queued_at DESC) WHERE subject_type IS NOT NULL;
            CREATE INDEX notification_log_user_idx
              ON notification_log (user_id, queued_at DESC) WHERE user_id IS NOT NULL;
            CREATE INDEX notification_log_suppression_idx
              ON notification_log (channel, recipient) WHERE status IN ('bounced','complained');
            CREATE INDEX notification_log_pending_idx
              ON notification_log (queued_at) WHERE status = 'queued';

            ALTER TABLE companies ADD COLUMN accounts_email citext;

            ALTER TABLE attachments DROP CONSTRAINT attachments_attachable_type_chk;
            ALTER TABLE attachments ADD CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
              ('b2b_application','rma','purchase_order','container','product','sku','invoice','quote','credit_note')) NOT VALID;
            ALTER TABLE attachments VALIDATE CONSTRAINT attachments_attachable_type_chk;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attachments DROP CONSTRAINT attachments_attachable_type_chk;
            ALTER TABLE attachments ADD CONSTRAINT attachments_attachable_type_chk CHECK (attachable_type IN
              ('b2b_application','rma','purchase_order','container','product','sku','invoice'));
            ALTER TABLE companies DROP COLUMN IF EXISTS accounts_email;
            DROP TABLE IF EXISTS notification_log;
            DROP TABLE IF EXISTS notification_preferences;
        SQL);
    }
};
