<?php

namespace App\Domain\Notifications;

use App\Models\NotificationLog;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Applies a provider's delivery outcome to its `notification_log` row
 * (05.12 §10.2). Matched on (channel, provider_message_id); unknown ids are
 * ignored. Idempotent and forward-only: a replayed event, or a late
 * `delivered` after a complaint, leaves the row as it is.
 */
final class DeliveryEvents
{
    /** @return bool whether a row moved */
    public function apply(NotificationChannel $channel, string $providerMessageId, NotificationStatus $next, ?DateTimeInterface $at, ?string $detail = null): bool
    {
        return DB::transaction(function () use ($channel, $providerMessageId, $next, $at, $detail) {
            $log = NotificationLog::query()
                ->where('channel', $channel->value)
                ->where('provider_message_id', $providerMessageId)
                ->lockForUpdate()
                ->first();

            if ($log === null || ! NotificationStatus::from($log->status)->canAdvanceTo($next)) {
                return false;
            }

            $changes = ['status' => $next->value];
            if ($next === NotificationStatus::Delivered) {
                $changes['delivered_at'] = $at ?? now();
            }
            if ($detail !== null) {
                $changes['last_error'] = mb_substr($detail, 0, 1000);
            }
            $log->update($changes);

            return true;
        });
    }
}
