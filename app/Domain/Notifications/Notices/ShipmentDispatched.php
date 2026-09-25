<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;

/**
 * 05.12 §5.1 `shipment.dispatched` — sent for every shipment, full or
 * partial (05.5 §7.2): what shipped, what remains, and the tracking. A
 * wholesale customer receiving 8 of 12 cases needs to know the other 4
 * are coming, not wonder whether the order was wrong.
 *
 * Quantities are stated in packs with their unit equivalent (invariant
 * 2). No prices: this is a delivery notice, and the invoice is its own
 * message.
 */
final class ShipmentDispatched extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $shipmentId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::ShipmentDispatched;
    }

    public function subject(): array
    {
        return ['shipment', $this->shipmentId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $shipment = Shipment::query()->findOrFail($this->shipmentId);
        $order = Order::query()->findOrFail($shipment->order_id);
        $orderLines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get()->keyBy('id');
        $shipped = ShipmentLine::query()->where('shipment_id', $shipment->id)->get();

        $paragraphs = [];
        $paragraphs[] = $shipment->fulfilment_type === 'collection'
            ? "Order {$order->order_number} has been collected. This is what left with you:"
            : "Order {$order->order_number} is on its way. This shipment contains:";

        foreach ($shipped as $line) {
            $orderLine = $orderLines->get($line->order_line_id);
            if ($orderLine !== null) {
                $paragraphs[] = '• '.self::quantity($line->dispatched_base_qty, $orderLine)." — {$orderLine->sku_code_snapshot} {$orderLine->name_snapshot}";
            }
        }

        $remaining = $orderLines->filter(fn (OrderLine $l) => $l->getAttribute('dispatched_base_qty') < $l->base_qty);
        if ($remaining->isEmpty()) {
            $paragraphs[] = 'That completes your order.';
        } else {
            $paragraphs[] = 'Still to come on this order:';
            foreach ($remaining as $line) {
                $paragraphs[] = '• '.self::quantity($line->base_qty - (int) $line->getAttribute('dispatched_base_qty'), $line)." — {$line->sku_code_snapshot} {$line->name_snapshot}";
            }
            $paragraphs[] = 'We will email you again when it ships.';
        }

        $facts = [
            ['label' => 'Order number', 'value' => $order->order_number],
            ['label' => 'Dispatched', 'value' => self::date($shipment->dispatched_at)],
        ];
        if ($shipment->carrier !== null && $shipment->carrier !== '') {
            $facts[] = ['label' => 'Carrier', 'value' => $shipment->carrier];
        }
        if ($shipment->tracking_number !== null && $shipment->tracking_number !== '') {
            $facts[] = ['label' => 'Tracking number', 'value' => $shipment->tracking_number];
        }
        if ($shipment->parcel_count !== null) {
            $facts[] = ['label' => 'Parcels', 'value' => (string) $shipment->parcel_count];
        }
        if ($order->customer_reference !== null && $order->customer_reference !== '') {
            $facts[] = ['label' => 'Your reference', 'value' => $order->customer_reference];
        }

        $complete = $remaining->isEmpty();

        return new MailContent(
            subject: $complete ? "Order {$order->order_number} dispatched" : "Order {$order->order_number}: part dispatched",
            heading: $complete ? 'Your order has been dispatched' : 'Part of your order has been dispatched',
            paragraphs: $paragraphs,
            facts: $facts,
            actionLabel: 'View your order',
            actionUrl: route('orders.confirmation', ['order' => $order->public_id]),
        );
    }

    /** "3 × Outer of 12 (36 units)", or units alone when not whole packs. */
    private static function quantity(int $baseQty, OrderLine $line): string
    {
        $units = number_format($baseQty).' '.($baseQty === 1 ? 'unit' : 'units');
        if ($line->pack_base_units <= 1) {
            return $units;
        }
        if ($baseQty % $line->pack_base_units !== 0) {
            return $units;
        }

        return intdiv($baseQty, $line->pack_base_units)." × {$line->pack_label_snapshot} ({$units})";
    }
}
