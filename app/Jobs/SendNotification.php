<?php

namespace App\Jobs;

use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationStatus;
use App\Domain\Notifications\Recipient;
use App\Mail\NotificationMail;
use App\Models\Attachment;
use App\Models\NotificationLog;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one queued `notification_log` row (05.12 §9–10). Content is built
 * now, from the notice's ids, so it reflects the record at send time.
 *
 * Transient failures are retried with backoff for 24 hours, the window
 * 06 §11 uses for webhooks, then the row is `failed`. A row that is no
 * longer `queued` (already sent by an earlier attempt) is left alone.
 */
class SendNotification implements ShouldQueue
{
    use Queueable;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 7200];

    public function __construct(
        public readonly int $logId,
        public readonly Notice $notice,
        public readonly Recipient $recipient,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(): void
    {
        $log = NotificationLog::query()->find($this->logId);
        if ($log === null || $log->status !== NotificationStatus::Queued->value) {
            return;
        }

        $attachment = $log->attachment_id === null ? null : Attachment::query()->find($log->attachment_id);
        $mail = new NotificationMail($this->notice->content($this->recipient), $attachment);

        $log->increment('attempts');

        try {
            $sent = Mail::to($this->recipient->email, $this->recipient->name)->send($mail);
        } catch (Throwable $e) {
            $log->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        $log->update([
            'status' => NotificationStatus::Sent->value,
            'sent_at' => now(),
            'provider_message_id' => $sent?->getMessageId(),
            'last_error' => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        NotificationLog::query()
            ->whereKey($this->logId)
            ->where('status', NotificationStatus::Queued->value)
            ->update([
                'status' => NotificationStatus::Failed->value,
                'failed_at' => now(),
                'last_error' => $e === null ? 'failed' : mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
    }
}
