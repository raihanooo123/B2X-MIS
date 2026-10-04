<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Order;
use App\Models\Rma;

/** 05.12 §5.1 `rma.requested` — 05.4 §7.1, §13.4: a consumer reported a problem; a handler reviews it. */
final class RmaRequested extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaRequested;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->findOrFail($this->rmaId);
        $order = Order::query()->find($rma->order_id, ['id', 'order_number']);

        return new MailContent(
            subject: "Problem reported: {$rma->rma_number}",
            heading: 'A customer reported a problem with their order',
            paragraphs: [
                'Review it on the Returns screen: approve it (we collect the goods at our cost) or reject it with a reason.',
                'Customer\'s description: '.($rma->reason_detail ?? '—'),
            ],
            facts: array_values(array_filter([
                ['label' => 'Return', 'value' => $rma->rma_number],
                $order !== null ? ['label' => 'Order', 'value' => $order->order_number] : null,
                ['label' => 'Reason', 'value' => str_replace('_', ' ', $rma->return_reason)],
            ])),
        );
    }
}
