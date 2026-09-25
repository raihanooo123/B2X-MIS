<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §7A.4 Pass 3's order-level totals (§6.4), plus the winning
 * spend break's identity for `orders.spend_break_id` /
 * `orders.spend_break_discount_minor` (02 §8.2).
 */
final readonly class OrderPricingResult
{
    /**
     * @param  list<PricedOrderLine>  $lines
     */
    public function __construct(
        public array $lines,
        public ?int $spendBreakId,
        public int $spendBreakDiscountMinor,
        public int $subtotalNetMinor,
        public int $taxMinor,
        public int $shippingNetMinor,
        public int $totalGrossMinor,
        public int $shippingTaxMinor = 0,
        public ?int $shippingTaxRateBp = null,
    ) {}

    /**
     * Adds carriage to an order priced without it (05.6 §5, 02 §20.2).
     * Carriage is rated on the post-spend-break subtotal, which only exists
     * once the goods are priced, so it is applied afterwards rather than
     * passed into OrderPricingPipeline.
     *
     *   shipping_tax_minor = round_half_up(shipping_net_minor × rate_bp / 10000)
     *   tax_minor          = Σ line_tax_minor + shipping_tax_minor
     *   total_gross_minor  = subtotal_net_minor + shipping_net_minor + tax_minor
     *
     * One rounding for carriage VAT, at carriage's own rate. Replaces any
     * shipping the result already carried.
     */
    public function withShipping(int $shippingNetMinor, ?int $shippingTaxRateBp): self
    {
        $shippingTax = $shippingTaxRateBp === null || $shippingNetMinor === 0
            ? 0
            : Money::roundHalfUpDiv($shippingNetMinor * $shippingTaxRateBp, 10000);
        $linesTax = $this->taxMinor - $this->shippingTaxMinor;
        $tax = $linesTax + $shippingTax;

        return new self(
            lines: $this->lines,
            spendBreakId: $this->spendBreakId,
            spendBreakDiscountMinor: $this->spendBreakDiscountMinor,
            subtotalNetMinor: $this->subtotalNetMinor,
            taxMinor: $tax,
            shippingNetMinor: $shippingNetMinor,
            totalGrossMinor: $this->subtotalNetMinor + $shippingNetMinor + $tax,
            shippingTaxMinor: $shippingTax,
            shippingTaxRateBp: $shippingTaxRateBp,
        );
    }
}
