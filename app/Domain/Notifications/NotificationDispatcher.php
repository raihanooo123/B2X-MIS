<?php

namespace App\Domain\Notifications;

use App\Jobs\SendNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one path every notification takes (05.12 §9, ROADMAP §22). Domain
 * services never build or send mail themselves.
 *
 * Always after commit: send() defers to DB::afterCommit(), which runs at
 * once outside a transaction, so a rolled-back transaction sends nothing
 * and no mail work ever runs while a stock or credit lock is held
 * (CLAUDE.md invariant 6, 04 §4.4).
 *
 * Per recipient, de-duplicated by address:
 *   1. suppressed (05.12 §10.4) → a `suppressed` row, nothing sent;
 *   2. otherwise a `queued` row, `ON CONFLICT (dedup_key) DO NOTHING` —
 *      a repeated event finds its row already there and sends nothing;
 *   3. a SendNotification job per new row.
 *
 * The marketing catalogue is empty by design, so no consent check is
 * wired here yet; one belongs in step 1 when a marketing key exists
 * (05.12 §8.1, §9).
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly Suppression $suppression = new Suppression,
    ) {}

    /**
     * @param  iterable<Recipient>  $recipients
     */
    public function send(Notice $notice, iterable $recipients): void
    {
        $unique = [];
        foreach ($recipients as $recipient) {
            if ($recipient->email !== '') {
                $unique[$recipient->email] ??= $recipient;
            }
        }

        if ($unique === []) {
            Log::warning('Notification has no recipient.', ['key' => $notice->key()->value, 'subject' => $notice->subject()]);

            return;
        }

        DB::afterCommit(function () use ($notice, $unique) {
            foreach ($unique as $recipient) {
                $this->queue($notice, $recipient);
            }
        });
    }

    private function queue(Notice $notice, Recipient $recipient): void
    {
        $key = $notice->key();
        $channel = NotificationChannel::Email;
        $suppressed = $this->suppression->reason($key, $channel, $recipient->email);
        [$subjectType, $subjectId] = $notice->subject() ?? [null, null];

        $row = DB::selectOne(<<<'SQL'
            INSERT INTO notification_log
              (notification_key, category, channel, template_version, user_id, company_id, recipient,
               subject_type, subject_id, attachment_id, dedup_key, status, last_error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (dedup_key) DO NOTHING
            RETURNING id
            SQL, [
            $key->value,
            $key->category()->value,
            $channel->value,
            $key->templateVersion(),
            $recipient->userId,
            $recipient->companyId,
            $recipient->email,
            $subjectType,
            $subjectId,
            $notice->attachmentId(),
            $notice->dedupKey($recipient),
            $suppressed === null ? NotificationStatus::Queued->value : NotificationStatus::Suppressed->value,
            $suppressed,
        ]);

        if ($row !== null && $suppressed === null) {
            SendNotification::dispatch((int) $row->id, $notice, $recipient);
        }
    }
}
