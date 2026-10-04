<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Order;
use App\Models\Payment;

/**
 * 05.12 §5.1 `refund.failed` — 05.4 §13.6. A consumer's card refund failed
 * at the gateway. A consumer has no account to hold a balance, so accounts
 * repay by bank transfer before the refund deadline.
 */
final class RefundFailed extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $refundPaymentId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RefundFailed;
    }

    public function subject(): array
    {
        return ['payment', $this->refundPaymentId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $refund = Payment::query()->findOrFail($this->refundPaymentId);
        $order = $refund->order_id === null ? null : Order::query()->find($refund->order_id, ['id', 'order_number', 'cancelled_at']);

        return new MailContent(
            subject: 'Card refund failed'.($order !== null ? ": order {$order->order_number}" : ''),
            heading: 'A card refund could not be made',
            paragraphs: [
                'The card refund below was refused by the payment provider. Please repay the customer by bank transfer and record it. Consumer refunds have a legal deadline of 14 days.',
            ],
            facts: array_values(array_filter([
                $order !== null ? ['label' => 'Order', 'value' => $order->order_number] : null,
                ['label' => 'Amount', 'value' => self::money($refund->amount_minor)],
                ['label' => 'Reason', 'value' => (string) ($refund->failure_reason ?? 'Not given')],
                $order?->cancelled_at !== null ? ['label' => 'Cancelled', 'value' => self::date($order->cancelled_at)] : null,
            ])),
        );
    }
}
