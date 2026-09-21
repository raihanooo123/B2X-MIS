<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §7A.4's Pass 3 output for one line — the final, persistable
 * values matching `order_lines` (02 §8.3) column-for-column:
 * `unit_price_net_e4`, `line_discount_minor`, `line_spend_discount_minor`,
 * `line_net_minor`, `tax_rate_bp`, `line_tax_minor`, `line_gross_minor`,
 * plus the provenance columns (`price_source`, `price_list_id`,
 * `price_list_item_id`, `applied_break_qty`).
 *
 * `unitCostE4`/`skuCostId` are carried straight through from Pass 1's
 * `ResolvedPrice` (see its docblock) rather than re-derived here — cost
 * does not change across passes 2-3, and CLAUDE.md invariant 4 requires
 * `order_lines.unit_cost_e4`/`sku_cost_id` to be the cost snapshotted at
 * resolution time, not re-resolved when the line is persisted.
 */
final readonly class PricedOrderLine
{
    public function __construct(
        public int $skuId,
        public int $baseQty,
        public int $unitPriceNetE4,
        public PriceSource $priceSource,
        public ?int $priceListId,
        public ?int $priceListItemId,
        public int $appliedBreakQty,
        public int $lineDiscountMinor,
        public int $lineSpendDiscountMinor,
        public int $lineNetMinor,
        public int $taxRateBp,
        public int $lineTaxMinor,
        public int $lineGrossMinor,
        public ?int $unitCostE4,
        public ?int $skuCostId,
    ) {}
}
