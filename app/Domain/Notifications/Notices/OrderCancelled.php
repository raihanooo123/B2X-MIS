<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use App\Models\Payment;

/**
 * 05.12 §5.1 `order.cancelled` — 05.4 §13.2. The acknowledgement of a
 * consumer's cancellation on a durable medium (CCR reg. 32(4)), with what
 * happens to their money.
 */
final class OrderCancelled extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $orderId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::OrderCancelled;
    }

    public function subject(): array
    {
        return ['order', $this->orderId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $order = Order::query()->findOrFail($this->orderId);

        return new MailContent(
            subject: "Order {$order->order_number} cancelled",
            heading: 'Your order is cancelled',
            paragraphs: [
                "We have cancelled order {$order->order_number}, as you asked, on ".self::date($order->cancelled_at).'.',
                ...$this->moneyParagraphs($order),
            ],
            facts: [
                ['label' => 'Order number', 'value' => $order->order_number],
                ['label' => 'Order total', 'value' => self::money($order->total_gross_minor)],
            ],
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }

    /**
     * @return list<string>
     */
    private function moneyParagraphs(Order $order): array
    {
        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $paragraphs = [];

        foreach ($payments->where('type', 'refund') as $refund) {
            $original = $payments->firstWhere('id', $refund->refunded_payment_id);
            $paragraphs[] = $refund->gateway === 'stripe'
                ? 'We are refunding '.self::money($refund->amount_minor).' to your card'.($original?->card_last4 !== null ? " ending {$original->card_last4}" : '').'. Your bank may take a few days to show it.'
                : 'We will refund '.self::money($refund->amount_minor).' by bank transfer within 14 days, and will contact you for your bank details.';
        }

        if ($paragraphs === [] && $payments->where('type', 'payment')->where('status', 'voided')->isNotEmpty()) {
            $paragraphs[] = 'The payment held on your card has been released. You have not been charged.';
        }

        return $paragraphs === [] ? ['No payment was taken for this order.'] : $paragraphs;
    }
}
