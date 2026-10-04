<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Order;
use App\Models\Rma;

/**
 * 05.12 §5.1 `rma.refund_due_soon` — 05.4 §13.5. A consumer refund falls
 * due in 3 days and has not been made. Once per deadline: the occurrence is
 * the due date, so a recomputed (earlier) deadline alerts again.
 */
final class RmaRefundDueSoon extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId, public readonly string $dueOn) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaRefundDueSoon;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function occurrence(): string
    {
        return $this->dueOn;
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->findOrFail($this->rmaId);
        $order = Order::query()->find($rma->order_id, ['id', 'order_number']);

        return new MailContent(
            subject: "Consumer refund due {$this->dueOn}: {$rma->rma_number}",
            heading: 'A consumer refund falls due in 3 days',
            paragraphs: [
                'The law requires this refund by the date below. Inspect the goods and make the refund before then, or refund now if the goods have not arrived but the customer has given proof of sending.',
            ],
            facts: array_values(array_filter([
                ['label' => 'Return', 'value' => $rma->rma_number],
                $order !== null ? ['label' => 'Order', 'value' => $order->order_number] : null,
                ['label' => 'Refund due by', 'value' => self::date($rma->refund_due_on)],
                ['label' => 'Status', 'value' => $rma->status],
            ])),
        );
    }
}
