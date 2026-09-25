<?php

namespace App\Domain\Billing;

use App\Domain\Pricing\Money;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * A shipment's share of each order line's money — 05.5 §7.3 as amended
 * 2026-09-25: **pro-rata by base quantity, remainder to the shipment that
 * completes the line**, so the per-shipment invoices of a fully dispatched
 * order sum to the order's line totals to the penny.
 *
 * Computed by cumulative rounding. For a line total T over `base_qty` B,
 * with Q units shipped up to and including a shipment:
 *
 *     cumulative(Q) = T                                  if Q ≥ B
 *                   = round_half_up(T × Q / B)           otherwise
 *
 * and the shipment's share is cumulative(Q) − cumulative(Q_before). The
 * shares telescope to T, and the rounding happens once per figure
 * (CLAUDE.md invariant 1). Applied independently to `line_net_minor`,
 * `line_tax_minor` and the line's discount (`line_discount_minor +
 * line_spend_discount_minor`, for display only — it is already inside
 * the net). Nothing is re-priced: every figure comes from the order-line
 * snapshot (invariant 4).
 *
 * "Before" means shipments of the same order dispatched earlier, ordered
 * by (dispatched_at, id). Both are fixed once a shipment is dispatched,
 * so a shipment's shares — and its invoice document — are reproducible
 * for ever from `shipment_lines` alone.
 */
final class ShipmentInvoiceShares
{
    /**
     * @return list<ShipmentLineShare> in line_no order
     */
    public function forShipment(Shipment $shipment): array
    {
        if ($shipment->status !== 'dispatched' || $shipment->dispatched_at === null) {
            throw new LogicException("Shipment {$shipment->id} is not dispatched; it has nothing to invoice.");
        }

        $lines = ShipmentLine::query()->where('shipment_id', $shipment->id)->get();
        $orderLines = OrderLine::query()->whereIn('id', $lines->pluck('order_line_id'))->orderBy('line_no')->get();
        $shippedHere = $lines->pluck('dispatched_base_qty', 'order_line_id');

        $shippedBefore = ShipmentLine::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
            ->where('shipments.order_id', $shipment->order_id)
            ->where('shipments.status', 'dispatched')
            ->where(fn (Builder $q) => $q
                ->where('shipments.dispatched_at', '<', $shipment->dispatched_at)
                ->orWhere(fn (Builder $q) => $q->where('shipments.dispatched_at', $shipment->dispatched_at)->where('shipments.id', '<', $shipment->id)))
            ->whereIn('shipment_lines.order_line_id', $orderLines->pluck('id'))
            ->groupBy('shipment_lines.order_line_id')
            ->selectRaw('shipment_lines.order_line_id, SUM(shipment_lines.dispatched_base_qty) AS qty')
            ->pluck('qty', 'order_line_id');

        $shares = [];
        foreach ($orderLines as $line) {
            $before = (int) ($shippedBefore[$line->id] ?? 0);
            $here = (int) $shippedHere[$line->id];
            $after = $before + $here;
            $discount = (int) $line->getAttribute('line_discount_minor') + (int) $line->getAttribute('line_spend_discount_minor');

            $shares[] = new ShipmentLineShare(
                $line,
                $here,
                self::share($line->line_net_minor, $before, $after, $line->base_qty),
                self::share($line->line_tax_minor, $before, $after, $line->base_qty),
                self::share($discount, $before, $after, $line->base_qty),
            );
        }

        return $shares;
    }

    public static function share(int $totalMinor, int $shippedBefore, int $shippedAfter, int $baseQty): int
    {
        return self::cumulative($totalMinor, $shippedAfter, $baseQty) - self::cumulative($totalMinor, $shippedBefore, $baseQty);
    }

    private static function cumulative(int $totalMinor, int $shipped, int $baseQty): int
    {
        if ($shipped >= $baseQty) {
            return $totalMinor;
        }

        return Money::roundHalfUpDiv($totalMinor * $shipped, $baseQty);
    }
}
