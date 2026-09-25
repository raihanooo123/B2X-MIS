<?php

namespace App\Domain\Notifications;

/**
 * 05.12 §4 — `notification_log.category`, single source for its CHECK list.
 * Fixed per notification in code, never chosen per send.
 */
enum NotificationCategory: string
{
    /** A message the service needs in order to work. Cannot be opted out of. */
    case Transactional = 'transactional';

    /** Account-safety notices. Cannot be opted out of; attempted even to a bounced address. */
    case Security = 'security';

    /** Consent-based (07 §7.1). The launch catalogue has none, by design. */
    case Marketing = 'marketing';
}
