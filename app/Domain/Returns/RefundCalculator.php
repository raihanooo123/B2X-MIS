<?php

namespace App\Domain\Returns;

use App\Domain\Pricing\Money;
use InvalidArgumentException;

/**
 * 05.4 §13.5 — a consumer return's refund, as pure integer arithmetic
 * (03 §6, half-up, CLAUDE.md invariant 1). No database, so it is the same
 * function whichever way it is reached, and testable on its own.
 *
 * Per line:
 *   refundable_goods = round_half_up(line_goods_net × refundable_qty / requested_qty)
 *                      (what was charged for the units refunded; exact for all of them)
 *   line_refund_net  = max(0, refundable_goods − diminished_value)
 *   line_refund_tax  = round_half_up(line_refund_net × tax_rate_bp / 10000)
 *
 * Delivery, when it is refunded at all:
 *   delivery_refund_tax = round_half_up(delivery_refund_net × shipping_tax_rate_bp / 10000)
 *
 *   refund_gross = Σ(line net + line tax) + delivery net + delivery tax — exactly.
 *
 * There is no restocking fee term: a consumer never pays one.
 */
final class RefundCalculator
{
    /**
     * @param  list<array{goods_net_minor: int, requested_base_qty: int, refundable_base_qty: int, diminished_value_minor: int, tax_rate_bp: int}>  $lines
     * @return array{lines: list<array{net_minor: int, tax_minor: int}>, delivery_net_minor: int, delivery_tax_minor: int, net_minor: int, tax_minor: int, gross_minor: int}
     */
    public static function calculate(array $lines, int $deliveryNetMinor = 0, int $shippingTaxRateBp = 0): array
    {
        if ($deliveryNetMinor < 0 || $shippingTaxRateBp < 0) {
            throw new InvalidArgumentException('Delivery refund and its tax rate cannot be negative.');
        }

        $out = [];
        $net = 0;
        $tax = 0;
        foreach ($lines as $line) {
            if ($line['requested_base_qty'] <= 0 || $line['refundable_base_qty'] < 0 || $line['diminished_value_minor'] < 0) {
                throw new InvalidArgumentException('Invalid refund line.');
            }

            $refundable = min($line['refundable_base_qty'], $line['requested_base_qty']);
            $goods = Money::roundHalfUpDiv($line['goods_net_minor'] * $refundable, $line['requested_base_qty']);
            $lineNet = max(0, $goods - $line['diminished_value_minor']);
            $lineTax = Money::roundHalfUpDiv($lineNet * $line['tax_rate_bp'], 10000);

            $out[] = ['net_minor' => $lineNet, 'tax_minor' => $lineTax];
            $net += $lineNet;
            $tax += $lineTax;
        }

        $deliveryTax = Money::roundHalfUpDiv($deliveryNetMinor * $shippingTaxRateBp, 10000);

        return [
            'lines' => $out,
            'delivery_net_minor' => $deliveryNetMinor,
            'delivery_tax_minor' => $deliveryTax,
            'net_minor' => $net,
            'tax_minor' => $tax,
            'gross_minor' => $net + $tax + $deliveryNetMinor + $deliveryTax,
        ];
    }
}
