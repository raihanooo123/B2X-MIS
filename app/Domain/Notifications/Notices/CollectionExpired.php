<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Collection\CollectionSlots;
use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\CollectionBooking;
use App\Models\Order;

/**
 * 05.12 / 05.6 §7A.10 `collection.expired` — a pay-at-collection order was
 * not collected and paid by its deadline, so it was cancelled and its
 * stock released (§7A.5). Nothing was taken, so nothing is refunded.
 */
final class CollectionExpired extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $orderId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::CollectionExpired;
    }

    public function subject(): array
    {
        return ['order', $this->orderId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $order = Order::query()->findOrFail($this->orderId);
        $slot = CollectionBooking::query()->with('slot')->where('order_id', $order->id)->first()?->slot;

        return new MailContent(
            subject: "Order {$order->order_number} cancelled — not collected",
            heading: 'Your order was not collected',
            paragraphs: [
                "Order {$order->order_number} was to be collected and paid for in cash"
                    .($slot === null ? '' : ' on '.CollectionSlots::label($slot)).'. It was not collected by the payment deadline, so we have cancelled it and released the goods.',
                'You have not been charged anything. If you still want the goods, please place a new order.',
            ],
            facts: [
                ['label' => 'Order number', 'value' => $order->order_number],
                ['label' => 'Order total', 'value' => self::money($order->total_gross_minor)],
            ],
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
