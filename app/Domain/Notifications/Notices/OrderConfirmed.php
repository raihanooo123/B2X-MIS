<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Accounts\TermsAcceptanceSource;
use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\PaymentMethod;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\PreContractInformation;
use App\Models\Order;
use App\Models\TermsAcceptance;

/**
 * 05.12 §5.1 `order.confirmed` — 04 §4.4, 05.3 §8.
 *
 * A public order's confirmation is also the confirmation on a durable
 * medium (CCR reg. 16, 05.15 §7.1): it carries the pre-contract
 * information in full, the terms of sale version accepted, and the model
 * cancellation form. A trade order's does not.
 */
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
            sections: $order->company_id === null ? $this->consumerSections($order) : [],
        );
    }

    /**
     * 05.15 §7.1: the terms are the version this order accepted, not
     * whatever is current when the email is rendered.
     *
     * @return list<array{heading: string, paragraphs: list<string>}>
     */
    private function consumerSections(Order $order): array
    {
        $brand = Branding::current();
        $terms = TermsAcceptance::query()
            ->with('termsVersion')
            ->where('order_id', $order->id)
            ->where('source', TermsAcceptanceSource::Checkout->value)
            ->first()?->termsVersion;

        return [
            ...PreContractInformation::build($brand, $terms, self::money($order->total_gross_minor))->sections,
            PreContractInformation::modelCancellationForm($brand),
        ];
    }
}
