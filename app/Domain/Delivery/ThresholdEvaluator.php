<?php

namespace App\Domain\Delivery;

use App\Models\DeliveryZone;
use App\Models\SystemConfiguration;

/**
 * 05.6 §6 — the two order-value thresholds, independent of each other and
 * both measured on the **post-spend-break net subtotal** (after item
 * pricing and 03 §7A's spend break, before carriage and VAT):
 *
 *   - `orders.minimum_value_net_minor` — smallest order accepted at all.
 *     No default: the go-live value is a client decision (05.6 §13 Q1), so
 *     unconfigured means no minimum, never a guess.
 *   - `delivery.carriage_paid_threshold_net_minor` — free delivery at or
 *     above this. A zone's own `carriage_paid_threshold_minor` beats it
 *     (§4.2: "free over £500 to the mainland but over £1,200 to the
 *     Highlands"); unconfigured falls back to 05.6 §6's £500.
 *
 * Both resolve most-specific-first, company then global (02 §2.7). Because
 * the subtotal is post-spend-break, a spend break that drops an order
 * below the carriage-paid threshold brings carriage back (05.6 §10).
 */
final class ThresholdEvaluator
{
    public const MINIMUM_ORDER_KEY = 'orders.minimum_value_net_minor';

    public const CARRIAGE_PAID_KEY = 'delivery.carriage_paid_threshold_net_minor';

    public const DEFAULT_CARRIAGE_PAID_NET_MINOR = 50000;

    public function minimumOrderNetMinor(?int $companyId): ?int
    {
        return $this->config(self::MINIMUM_ORDER_KEY, $companyId);
    }

    /** Below the minimum — at exactly the minimum is allowed (05.6 §11). */
    public function belowMinimum(int $subtotalNetMinor, ?int $companyId): bool
    {
        $minimum = $this->minimumOrderNetMinor($companyId);

        return $minimum !== null && $subtotalNetMinor < $minimum;
    }

    public function carriagePaidThresholdNetMinor(?DeliveryZone $zone, ?int $companyId): int
    {
        return $zone->carriage_paid_threshold_minor
            ?? $this->config(self::CARRIAGE_PAID_KEY, $companyId)
            ?? self::DEFAULT_CARRIAGE_PAID_NET_MINOR;
    }

    public function isCarriagePaid(int $subtotalNetMinor, ?DeliveryZone $zone, ?int $companyId): bool
    {
        return $subtotalNetMinor >= $this->carriagePaidThresholdNetMinor($zone, $companyId);
    }

    private function config(string $key, ?int $companyId): ?int
    {
        $rows = SystemConfiguration::query()
            ->where('config_key', $key)
            ->where(fn ($q) => $q->where('scope', 'global')->when($companyId !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope', 'company')->where('company_id', $companyId))))
            ->get(['scope', 'value_int'])
            ->keyBy('scope');

        return ($rows->get('company') ?? $rows->get('global'))?->value_int;
    }
}
