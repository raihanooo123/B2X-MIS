<?php

namespace App\Domain\Notifications;

use App\Models\NotificationLog;

/**
 * 05.12 §10.4, derived from `notification_log` (no separate list):
 *
 *   - a hard bounce suppresses marketing and transactional mail to that
 *     address, until a later message to it is delivered — security notices
 *     and re-verification are still attempted, which is how an address
 *     comes back;
 *   - a spam complaint suppresses marketing only.
 */
final class Suppression
{
    /** @return string|null why the message must not be sent, or null to send it */
    public function reason(NotificationKey $key, NotificationChannel $channel, string $recipient): ?string
    {
        $category = $key->category();

        $complained = $category === NotificationCategory::Marketing
            && NotificationLog::query()
                ->where('channel', $channel->value)
                ->where('recipient', $recipient)
                ->where('status', NotificationStatus::Complained->value)
                ->exists();
        if ($complained) {
            return 'recipient complained';
        }

        if ($key->ignoresBounceSuppression()) {
            return null;
        }

        $lastBounceAt = NotificationLog::query()
            ->where('channel', $channel->value)
            ->where('recipient', $recipient)
            ->where('status', NotificationStatus::Bounced->value)
            ->max('updated_at');
        if ($lastBounceAt === null) {
            return null;
        }

        $deliveredSince = NotificationLog::query()
            ->where('channel', $channel->value)
            ->where('recipient', $recipient)
            ->where('status', NotificationStatus::Delivered->value)
            ->where('delivered_at', '>', $lastBounceAt)
            ->exists();

        return $deliveredSince ? null : 'recipient hard-bounced';
    }
}
