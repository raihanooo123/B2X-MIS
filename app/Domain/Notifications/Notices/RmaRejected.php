<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use App\Models\Rma;

/** 05.12 §5.1 `rma.rejected` — 05.4 §4: a problem report was not accepted, with the reason. */
final class RmaRejected extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId, public readonly string $reason) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaRejected;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->findOrFail($this->rmaId);
        $order = Order::query()->findOrFail($rma->order_id);

        return new MailContent(
            subject: "Your report {$rma->rma_number} for order {$order->order_number}",
            heading: 'We could not accept your report',
            paragraphs: [
                "We have looked at the problem you reported with order {$order->order_number} and could not accept it.",
                "Reason: {$this->reason}",
                'If you think this is wrong, please reply with more detail or photographs. Your legal rights under the Consumer Rights Act 2015 are not affected.',
            ],
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
