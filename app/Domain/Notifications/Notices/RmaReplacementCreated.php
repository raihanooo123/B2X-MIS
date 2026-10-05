<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Rma;

/**
 * 05.12 `rma.replacement_created` — 05.4 §14.2 R16. A replacement order
 * has been created for faulty goods: what is coming, that it costs the
 * customer nothing, and where to follow it. Its dispatch sends the usual
 * `shipment.dispatched`; `order.confirmed` is not sent for a replacement.
 */
final class RmaReplacementCreated extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaReplacementCreated;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->findOrFail($this->rmaId);
        $replacement = Order::query()->where('id', $rma->replacement_order_id)->firstOrFail();
        $original = Order::query()->findOrFail($rma->order_id);

        $facts = [
            ['label' => 'Return', 'value' => $rma->rma_number],
            ['label' => 'Replacement order', 'value' => $replacement->order_number],
            ['label' => 'Original order', 'value' => $original->order_number],
        ];
        foreach (OrderLine::query()->where('order_id', $replacement->id)->orderBy('line_no')->get() as $line) {
            $facts[] = ['label' => $line->name_snapshot, 'value' => "{$line->pack_qty} × {$line->pack_label_snapshot}"];
        }

        return new MailContent(
            subject: "Your replacement for return {$rma->rma_number}",
            heading: 'Your replacement is on its way',
            paragraphs: [
                "We are sending you replacements for the faulty items from return {$rma->rma_number}, at no cost to you.",
                'We will email you again when they leave our warehouse.',
            ],
            facts: $facts,
            actionLabel: 'View your replacement order',
            actionUrl: GuestOrderLink::customerUrl($replacement),
        );
    }
}
