<?php

namespace App\Http\Support;

use App\Domain\Ordering\PaymentMethod;
use App\Domain\Pricing\DeliveryCountries;
use App\Domain\Returns\CancellationEligibility;
use App\Domain\Storefront\PreContractInformation;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Rma;
use App\Models\RmaLine;
use Carbon\CarbonImmutable;

/**
 * The order page's `order` prop, for the signed-in confirmation page and a
 * guest's page by signed link (05.15 §6.2) alike. Everything shown is the
 * order's own snapshot (CLAUDE.md invariant 4) — never re-resolved.
 * Customer-facing: no cost or margin (invariant 9); `order_lines.unit_cost_e4`
 * is never read.
 */
final class OrderPageProps
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Order $model): array
    {
        $model->loadMissing(['lines' => fn ($q) => $q->orderBy('line_no'), 'addresses']);
        $address = $model->addresses->firstWhere('address_type', 'delivery');

        return [
            'id' => $model->public_id,
            'order_number' => $model->order_number,
            'placed_at' => $model->placed_at?->toIso8601ZuluString(),
            'status' => $model->status,
            // 05.4 §13.2: a consumer order not yet dispatched may be cancelled.
            'can_cancel' => self::canCancel($model),
            'cancelled_at' => $model->cancelled_at?->toIso8601ZuluString(),
            'refunds' => self::refunds($model),
            // 05.4 §13.3: cancelling some or all of a dispatched consumer order.
            'cancellation' => self::cancellation($model),
            'returns' => self::returns($model),
            'payment_status' => $model->payment_status,
            // 02 §18. Null only for orders placed before the column existed.
            'payment_method' => $model->payment_method === null ? null : PaymentMethod::tryFrom($model->payment_method)?->value,
            'customer_reference' => $model->customer_reference,
            // The card payment, if any: brand and last four only (07 §6.4).
            'card_payment' => self::cardPayment($model),
            'subtotal_net_minor' => $model->subtotal_net_minor,
            'spend_break_discount_minor' => $model->spend_break_discount_minor,
            'shipping_net_minor' => $model->shipping_net_minor,
            'shipping_tax_minor' => $model->shipping_tax_minor,
            // 02 §20 snapshot: zone and method as rated when the order was placed.
            'delivery' => $model->delivery_zone_id === null ? null : [
                'status' => $model->delivery_rate_id === null ? 'free' : 'rated',
                'zone_name' => DeliveryZone::query()->whereKey($model->delivery_zone_id)->value('name'),
                'method' => $model->delivery_method,
                'shipping_net_minor' => $model->shipping_net_minor,
                'shipping_tax_minor' => $model->shipping_tax_minor,
            ],
            'tax_minor' => $model->tax_minor,
            'total_gross_minor' => $model->total_gross_minor,
            'lines' => array_values($model->lines->map(fn (OrderLine $l) => [
                'line_no' => $l->line_no,
                'sku_code' => $l->sku_code_snapshot,
                'name' => $l->name_snapshot,
                'pack_label' => $l->pack_label_snapshot,
                'pack_qty' => $l->pack_qty,
                'pack_base_units' => $l->pack_base_units,
                'base_qty' => $l->base_qty,
                'unit_price_net_e4' => $l->unit_price_net_e4,
                'tax_rate_bp' => $l->tax_rate_bp,
                'line_net_minor' => $l->line_net_minor,
                'line_tax_minor' => $l->line_tax_minor,
                'line_gross_minor' => $l->line_gross_minor,
            ])->all()),
            'delivery_address' => $address instanceof OrderAddress ? [
                'contact_name' => $address->contact_name,
                'company_name' => $address->company_name,
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'county' => $address->county,
                'postcode' => $address->postcode,
                'country' => DeliveryCountries::name(trim($address->country_code)),
            ] : null,
        ];
    }

    /**
     * What may be cancelled now, line by line (CancellationEligibility is
     * the authority; ConsumerCancellations re-checks under lock). Null for
     * a trade order or one not yet dispatched.
     *
     * @return array<string, mixed>|null
     */
    private static function cancellation(Order $order): ?array
    {
        if ($order->company_id !== null || ! in_array($order->status, ['part_dispatched', 'dispatched', 'completed'], true)) {
            return null;
        }

        $eligibility = CancellationEligibility::for($order, CarbonImmutable::now());

        return [
            'available' => $eligibility->available,
            'message' => $eligibility->message,
            'last_day' => $eligibility->possession?->lastDay()->toDateString(),
            'return_statement' => $order->delivery_method === 'pallet'
                ? PreContractInformation::palletReturnStatement($order->return_cost_estimate_gross_minor)
                : 'You pay the cost of posting the items back.',
            'lines' => array_values(array_map(fn (array $l) => [
                'line_no' => $l['order_line']->line_no,
                'sku_code' => $l['order_line']->sku_code_snapshot,
                'name' => $l['order_line']->name_snapshot,
                'pack_label' => $l['order_line']->pack_label_snapshot,
                'returnable_pack_qty' => $l['returnable_pack_qty'],
                'eligible' => $l['eligible'],
                'refusal' => $l['refusal'],
                'notice' => $l['notice'],
            ], $eligibility->lines)),
        ];
    }

    /**
     * The order's returns, for the customer.
     *
     * @return list<array<string, mixed>>
     */
    private static function returns(Order $order): array
    {
        return array_values(Rma::query()
            ->with(['lines' => fn ($q) => $q->orderBy('line_no')])
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Rma $r) => [
                'id' => $r->public_id,
                'rma_number' => $r->rma_number,
                'status' => $r->status,
                'return_method' => $r->return_method,
                'return_by_date' => $r->return_by_date?->toDateString(),
                // 05.4 §13.5: proof of sending (upload time) and the refund deadline.
                'proof_sent_at' => $r->goods_sent_at?->toIso8601ZuluString(),
                'received_at' => $r->received_at?->toIso8601ZuluString(),
                'refund_due_on' => $r->refund_due_on?->toDateString(),
                'accepts_proof' => $r->status === 'awaiting_goods' && $r->return_method !== 'collection' && $r->return_reason === 'consumer_cancellation',
                'lines' => array_values($r->lines->map(fn (RmaLine $l) => ['sku_code' => $l->sku_code_snapshot, 'name' => $l->name_snapshot, 'pack_qty' => $l->requested_pack_qty])->all()),
            ])
            ->all());
    }

    /** Mirrors OrderCancellationService's own check, which is the authority. */
    public static function canCancel(Order $order): bool
    {
        return $order->company_id === null && in_array($order->status, ['confirmed', 'picking'], true);
    }

    /**
     * The order's refunds, for the customer: amount, how, and whether done.
     *
     * @return list<array{amount_minor: int, method: string, status: string}>
     */
    private static function refunds(Order $order): array
    {
        return array_values(Payment::query()
            ->where('order_id', $order->id)
            ->where('type', 'refund')
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $p) => [
                'amount_minor' => $p->amount_minor,
                'method' => $p->gateway === 'stripe' ? 'card' : 'bank_transfer',
                // A failed card refund is repaid by transfer: still "in progress" for the customer.
                'status' => $p->status === 'captured' ? 'refunded' : 'in_progress',
            ])
            ->all());
    }

    /**
     * @return array{status: string, card_brand: string|null, card_last4: string|null}|null
     */
    private static function cardPayment(Order $order): ?array
    {
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->where('gateway', 'stripe')
            ->where('type', 'payment')
            ->latest('id')
            ->first();

        return $payment === null ? null : [
            'status' => $payment->status,
            'card_brand' => $payment->card_brand,
            'card_last4' => $payment->card_last4,
        ];
    }
}
