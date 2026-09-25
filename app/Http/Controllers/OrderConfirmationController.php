<?php

namespace App\Http\Controllers;

use App\Domain\Ordering\PaymentMethod;
use App\Domain\Pricing\DeliveryCountries;
use App\Http\Support\PriceDisplay;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order confirmation page. Everything shown is the order's own
 * snapshot (CLAUDE.md invariant 4) — never re-resolved. Customer-facing:
 * no cost or margin (invariant 9); `order_lines.unit_cost_e4` is never read.
 */
class OrderConfirmationController extends Controller
{
    public function show(Request $request, string $order): Response
    {
        $model = Order::query()->where('public_id', $order)->with(['lines' => fn ($q) => $q->orderBy('line_no'), 'addresses'])->firstOrFail();
        Gate::authorize('view', $model);

        $address = $model->addresses->firstWhere('address_type', 'delivery');

        return Inertia::render('Orders/Confirmation', [
            'display_mode' => PriceDisplay::mode($request),
            'order' => [
                'id' => $model->public_id,
                'order_number' => $model->order_number,
                'placed_at' => $model->placed_at?->toIso8601ZuluString(),
                'status' => $model->status,
                'payment_status' => $model->payment_status,
                // 02 §18. Null only for orders placed before the column existed.
                'payment_method' => $model->payment_method === null ? null : PaymentMethod::tryFrom($model->payment_method)?->value,
                'customer_reference' => $model->customer_reference,
                // The card payment, if any: brand and last four only (07 §6.4).
                'card_payment' => $this->cardPayment($model),
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
            ],
        ]);
    }

    /**
     * @return array{status: string, card_brand: string|null, card_last4: string|null}|null
     */
    private function cardPayment(Order $order): ?array
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
