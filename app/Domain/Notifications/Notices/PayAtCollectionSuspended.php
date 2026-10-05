<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;

/**
 * 05.12 / 05.6 §7A.10 `collection.pay_at_collection_suspended` — the
 * customer reached the no-show limit (§7A.11). Pay at collection is no
 * longer offered; card payment and collection are unaffected.
 */
final class PayAtCollectionSuspended extends Notice
{
    public function __construct(public readonly int $suspensionId, public readonly int $noShowCount) {}

    public function key(): NotificationKey
    {
        return NotificationKey::PayAtCollectionSuspended;
    }

    public function subject(): array
    {
        return ['pay_at_collection_suspension', $this->suspensionId];
    }

    public function content(Recipient $recipient): MailContent
    {
        return new MailContent(
            subject: 'Paying cash at collection is no longer available',
            heading: 'Paying cash at collection is no longer available',
            paragraphs: [
                "{$this->noShowCount} orders booked to pay cash at collection were not collected, so we can no longer hold goods for you to pay for when you collect.",
                'You can still collect orders: please pay by card when you order. If you think this is a mistake, please contact us.',
            ],
            facts: [],
        );
    }
}
