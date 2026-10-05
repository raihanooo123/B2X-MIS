<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;

/** 05.10 §2.6: durable acknowledgement of a pre-dispatch part cancellation. */
final class OrderItemsCancelled extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $cancellationId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::OrderItemsCancelled;
    }

    public function subject(): array
    {
        return ['order_cancellation', $this->cancellationId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $cancellation = OrderCancellation::query()->findOrFail($this->cancellationId);
        $order = Order::query()->findOrFail($cancellation->order_id);
        $facts = [['label' => 'Order number', 'value' => $order->order_number]];

        foreach (DB::table('order_cancellation_lines')->where('order_cancellation_id', $cancellation->id)->orderBy('id')->get() as $cancelled) {
            $line = OrderLine::query()->where('id', $cancelled->order_line_id)->firstOrFail();
            $facts[] = ['label' => $line->name_snapshot, 'value' => "{$cancelled->cancelled_pack_qty} × {$line->pack_label_snapshot}"];
        }

        $gross = $cancellation->cancelled_gross_minor + $cancellation->delivery_refund_net_minor + $cancellation->delivery_refund_tax_minor;
        $facts[] = ['label' => 'Amount removed', 'value' => self::money($gross)];

        $money = $cancellation->refund_payment_id !== null
            ? 'We have started your refund. Card refunds may take a few days to appear; bank-transfer refunds are arranged by our accounts team.'
            : 'The amount due on your order has been reduced. If you have not paid yet, there is no refund to wait for.';

        return new MailContent(
            subject: "Items cancelled from order {$order->order_number}",
            heading: 'Your cancellation is confirmed',
            paragraphs: ['We have cancelled the items below from your order as requested.', $money],
            facts: $facts,
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
