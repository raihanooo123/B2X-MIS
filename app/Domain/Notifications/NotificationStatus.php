<?php

namespace App\Domain\Notifications;

/**
 * 05.12 §10.1 — `notification_log.status`, the latest outcome of one
 * message. Provider events move a row forward only: a late `delivered`
 * never overwrites `bounced` or `complained`.
 */
enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    /** Whether a provider event may move a row from this status to $next. */
    public function canAdvanceTo(self $next): bool
    {
        $rank = fn (self $status): ?int => match ($status) {
            self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Bounced => 3,
            self::Complained => 4,
            self::Failed, self::Suppressed => null,
        };

        $from = $rank($this);
        $to = $rank($next);

        return $from !== null && $to !== null && $to > $from;
    }
}
