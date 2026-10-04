<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * 05.12 §5.1 `order.access_link` — 05.15 §6.2 *Find my order*: a fresh
 * link to a guest's order page, sent to the order's guest email. Each
 * request is its own message (a resend is not a duplicate).
 */
final class OrderAccessLink extends Notice
{
    public readonly string $requestId;

    public function __construct(public readonly int $orderId)
    {
        $this->requestId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::OrderAccessLink;
    }

    public function subject(): array
    {
        return ['order', $this->orderId];
    }

    public function occurrence(): string
    {
        return $this->requestId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $order = Order::query()->findOrFail($this->orderId);

        return new MailContent(
            subject: "Your order {$order->order_number}",
            heading: 'Here is the link to your order',
            paragraphs: ["You asked for a link to order {$order->order_number}. Use the button below to see its status, items and delivery details."],
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::url($order),
            closing: ['The link works for 90 days. If you did not ask for it, you can ignore this email.'],
        );
    }
}
