<?php

namespace App\Domain\Delivery;

use App\Domain\Pricing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §5.2–5.3 — carriage for a weighed consignment in a zone.
 *
 * The band is the active, in-date `delivery_rates` row for (zone, method)
 * whose `weight_range` (grams, `[lower, upper)`) contains the weight — so
 * a weight exactly on a boundary belongs to the **higher** band — read
 * through the GiST `delivery_rates_resolve_idx`. The top band's upper bound
 * is open.
 *
 *   shipping_net_minor = price_net_minor
 *                      + (per_extra_kg_minor NULL ? 0
 *                         : round_half_up(max(0, weight_g − lower) × per_extra_kg_minor / 1000))
 *
 * One rounding, integer arithmetic, through Money (03 §6). No band for the
 * weight — or none for the method in this zone — returns null: the
 * consignment cannot be rated and goes to a manual quote, never a guess.
 */
final class CarriageCalculator
{
    public function rate(int $zoneId, string $method, int $weightG, ?CarbonImmutable $at = null): ?RatedCarriage
    {
        $at ??= CarbonImmutable::now();

        $row = DB::selectOne(
            <<<'SQL'
                SELECT id, lower(weight_range) AS lower_g, price_net_minor, per_extra_kg_minor, tax_class_id
                FROM   delivery_rates
                WHERE  zone_id = ?
                  AND  method = ?
                  AND  status = 'active'
                  AND  validity @> ?::timestamptz
                  AND  weight_range @> ?::integer
                ORDER BY lower(weight_range) DESC
                LIMIT  1
                SQL,
            [$zoneId, $method, $at->format('Y-m-d\TH:i:s.uP'), $weightG],
        );

        if ($row === null) {
            return null;
        }

        $lowerG = (int) ($row->lower_g ?? 0);
        $surcharge = $row->per_extra_kg_minor === null
            ? 0
            : Money::roundHalfUpDiv(max(0, $weightG - $lowerG) * (int) $row->per_extra_kg_minor, 1000);

        return new RatedCarriage(
            rateId: (int) $row->id,
            method: $method,
            bandLowerG: $lowerG,
            priceNetMinor: (int) $row->price_net_minor,
            surchargeNetMinor: $surcharge,
            taxClassId: (int) $row->tax_class_id,
        );
    }
}
