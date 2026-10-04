<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use App\Models\Rma;

/**
 * 05.12 §5.1 `rma.not_received` — 05.4 §7.6, §13.5. The send-back deadline
 * passed with neither the goods nor proof of sending, so the return is
 * closed without a refund.
 */
final class RmaNotReceived extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaNotReceived;
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
            subject: "Return {$rma->rma_number} closed: goods not received",
            heading: 'We did not receive your return',
            paragraphs: [
                "We have not received the items for return {$rma->rma_number} (order {$order->order_number}), and no proof of sending was given by ".self::date($rma->return_by_date).'. The return is closed, and no refund is due.',
                'If you did send the items, please contact us with your proof of postage.',
            ],
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
