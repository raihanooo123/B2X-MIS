<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\PaymentMethod;
use App\Models\Order;

/** 05.12 §5.1 `order.confirmed` — 04 §4.4, 05.3 §8. */
final class OrderConfirmed extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $orderId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::OrderConfirmed;
    }

    public function subject(): array
    {
        return ['order', $this->orderId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $order = Order::query()->withCount('lines')->findOrFail($this->orderId);
        $method = $order->payment_method === null ? null : PaymentMethod::tryFrom($order->payment_method);

        $facts = [
            ['label' => 'Order number', 'value' => $order->order_number],
            ['label' => 'Placed', 'value' => self::date($order->placed_at)],
            ['label' => 'Lines', 'value' => (string) $order->lines_count],
            ['label' => 'Goods (net)', 'value' => self::money($order->subtotal_net_minor)],
            ['label' => 'Carriage (net)', 'value' => self::money($order->shipping_net_minor)],
            ['label' => 'VAT', 'value' => self::money($order->tax_minor)],
            ['label' => 'Total', 'value' => self::money($order->total_gross_minor)],
        ];
        if ($method !== null) {
            $facts[] = ['label' => 'Payment', 'value' => $method->label()];
        }
        if ($order->customer_reference !== null && $order->customer_reference !== '') {
            $facts[] = ['label' => 'Your reference', 'value' => $order->customer_reference];
        }

        return new MailContent(
            subject: "Order {$order->order_number} confirmed",
            heading: 'Thank you — your order is confirmed',
            paragraphs: ["We have received order {$order->order_number} and reserved the stock for it. We will email you again when it is dispatched."],
            facts: $facts,
            actionLabel: 'View your order',
            actionUrl: route('orders.confirmation', ['order' => $order->public_id]),
        );
    }
}
