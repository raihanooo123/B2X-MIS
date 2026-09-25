<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Ordering\CheckoutBlocker;
use App\Domain\Ordering\CheckoutPreview;
use App\Models\CartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Doc 06 §9.2's payload, unwrapped exactly as the doc shows it.
 *
 * Customer-facing: no cost, no margin (CLAUDE.md invariant 9, 06 §10).
 * PricedOrderLine carries `unitCostE4`/`skuCostId`; this class never
 * reads them, and there is no field here to put them in.
 *
 * Differences from the §9.2 example, each for a reason:
 *   - `spend_break.id` → `spend_break.code`: `order_spend_breaks` has no
 *     `public_id`, and its internal id may not be exposed (06 §2).
 *     `next_threshold_net_minor`/`shortfall_to_next_minor` are null:
 *     SpendBreakResolver only finds the winning break, not the next one.
 *   - `delivery` follows §9.2 (`zone`, `method`, `shipping_net_minor`,
 *     `carriage_paid_threshold_net_minor`, `shortfall_to_free_minor`),
 *     plus additive fields: `status` (rated | free | manual_quote |
 *     unserviceable), `reason`, `zone_name`, `weight_g`,
 *     `shipping_tax_minor`, `tax_rate_bp`, `postcode_recognised`. Null when
 *     no `delivery_postcode` was sent — nothing to rate yet (05.6 §8).
 *   - Added `lines` and `minimum_order_net_minor` (additive, 06 §2) so
 *     the pad can show the server's figures and the footer's progress
 *     toward the minimum (05.1 §6).
 *
 * @property CheckoutPreview $resource
 */
class CheckoutPreviewResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(CheckoutPreview $preview)
    {
        parent::__construct($preview);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preview = $this->resource;

        return [
            'subtotal_net_minor' => $preview->subtotalNetMinor,
            'spend_break' => $preview->spendBreak === null ? null : [
                'code' => $preview->spendBreak->code,
                'name' => $preview->spendBreak->name,
                'discount_minor' => $preview->spendBreakDiscountMinor,
                'next_threshold_net_minor' => null,
                'shortfall_to_next_minor' => null,
            ],
            'delivery' => $this->delivery($preview),
            'tax_minor' => $preview->taxMinor,
            'total_gross_minor' => $preview->totalGrossMinor,
            'account_credit_applied_minor' => $preview->accountCreditAppliedMinor,
            'amount_due_minor' => $preview->amountDueMinor(),
            'credit' => $preview->creditAvailableMinor === null ? null : [
                'available_minor' => $preview->creditAvailableMinor,
                'sufficient' => $preview->creditAvailableMinor >= $preview->amountDueMinor(),
            ],
            'minimum_order_net_minor' => $preview->minimumOrderNetMinor,
            'lines' => array_map(fn (CartLine $line) => $this->line($preview, $line), $preview->cartLines),
            'blockers' => array_map(fn (CheckoutBlocker $b) => $b->toArray(), $preview->blockers),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function delivery(CheckoutPreview $preview): ?array
    {
        $quote = $preview->delivery;
        if ($quote === null) {
            return null;
        }

        return [
            'status' => $quote->status,
            'reason' => $quote->reason,
            'zone' => $quote->zone?->code,
            'zone_name' => $quote->zone?->name,
            'method' => $quote->method,
            'weight_g' => $quote->weightG,
            'shipping_net_minor' => $quote->isChargeable() ? $quote->shippingNetMinor : null,
            'shipping_tax_minor' => $quote->isChargeable() ? $preview->shippingTaxMinor : null,
            'tax_rate_bp' => $quote->taxRateBp,
            'carriage_paid_threshold_net_minor' => $quote->carriagePaidThresholdNetMinor,
            'shortfall_to_free_minor' => $quote->shortfallToFreeMinor,
            'postcode_recognised' => $quote->postcodeRecognised,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CheckoutPreview $preview, CartLine $line): array
    {
        $priced = $preview->pricedLines[$line->id] ?? null;

        return [
            'cart_line_id' => $line->public_id,
            'sku_id' => $line->sku?->public_id,
            'base_qty' => $line->base_qty,
            'priced' => $priced !== null,
            'unit_price_net_e4' => $priced?->unitPriceNetE4,
            'price_source' => $priced?->priceSource->value,
            'applied_break_qty' => $priced?->appliedBreakQty,
            'line_discount_minor' => $priced?->lineDiscountMinor,
            'line_spend_discount_minor' => $priced?->lineSpendDiscountMinor,
            'line_net_minor' => $priced?->lineNetMinor,
            'tax_rate_bp' => $priced?->taxRateBp,
            'line_tax_minor' => $priced?->lineTaxMinor,
            'line_gross_minor' => $priced?->lineGrossMinor,
        ];
    }
}
