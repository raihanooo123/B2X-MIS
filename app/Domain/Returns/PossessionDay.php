<?php

namespace App\Domain\Returns;

use App\Models\CollectionBooking;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\SystemConfiguration;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;

/**
 * 05.4 §13.3 — the day a consumer took possession of their goods, from
 * which their 14-day cancellation window runs (CCR reg. 30(3)).
 *
 *   - delivery, no carrier date (shipments have no delivered state yet,
 *     02 §14.6): the LAST shipment's dispatch date in UK time, plus the
 *     zone's `transit_days` counted as working days (Mon–Fri), plus 1 day
 *     for bank holidays — the latest plausible day, in the buyer's favour.
 *     A zone without `transit_days` uses `returns.consumer_default_transit_days`
 *     (global config, default 5; 05.15 §12 Q8).
 *   - collection: the day of collection — `collection_bookings.collected_at`
 *     as a UK date, card or cash alike (05.6 §7A.3, 05.15 §7.2) — and never
 *     dispatch + transit. Null until the booking is `collected`.
 *
 * Null while possession has not started: nothing dispatched, or the order
 * only part dispatched (the window runs from the last part, reg. 30(3)(b)).
 */
final readonly class PossessionDay
{
    public const DEFAULT_TRANSIT_DAYS_KEY = 'returns.consumer_default_transit_days';

    public const DEFAULT_TRANSIT_DAYS = 5;

    /** Days in the cancellation window. Law, not configuration. */
    public const WINDOW_DAYS = 14;

    public function __construct(
        public CarbonImmutable $date,
        public string $basis,
    ) {}

    public static function for(Order $order): ?self
    {
        if ($order->fulfilment_type === 'collection') {
            $collectedAt = CollectionBooking::query()->where('order_id', $order->id)
                ->where('status', 'collected')->value('collected_at');

            return $collectedAt === null ? null : new self(
                DisplayTime::local(CarbonImmutable::parse((string) $collectedAt))->startOfDay(),
                'collected',
            );
        }

        if (! in_array($order->status, ['dispatched', 'completed'], true)) {
            return null;
        }

        $lastDispatch = Shipment::query()->where('order_id', $order->id)->whereNotNull('dispatched_at')->max('dispatched_at');
        if ($lastDispatch === null) {
            return null;
        }

        $dispatchDay = DisplayTime::local(CarbonImmutable::parse((string) $lastDispatch))->startOfDay();
        $transit = $order->delivery_zone_id === null ? null : DeliveryZone::query()->whereKey($order->delivery_zone_id)->value('transit_days');

        return new self(
            self::addWorkingDays($dispatchDay, $transit === null ? self::defaultTransitDays() : (int) $transit)->addDay(),
            'estimated_from_dispatch',
        );
    }

    /** The last day a cancellation is accepted: the 14th day after possession. */
    public function lastDay(): CarbonImmutable
    {
        return $this->date->addDays(self::WINDOW_DAYS);
    }

    /** Whether `$at`, in UK time, is still within the window (requests before possession are too). */
    public function isOpen(CarbonImmutable $at): bool
    {
        return DisplayTime::local($at)->startOfDay()->lessThanOrEqualTo($this->lastDay());
    }

    public static function addWorkingDays(CarbonImmutable $day, int $workingDays): CarbonImmutable
    {
        $result = $day;
        for ($added = 0; $added < $workingDays;) {
            $result = $result->addDay();
            if (! $result->isWeekend()) {
                $added++;
            }
        }

        return $result;
    }

    private static function defaultTransitDays(): int
    {
        $value = SystemConfiguration::query()
            ->where('scope', 'global')
            ->where('config_key', self::DEFAULT_TRANSIT_DAYS_KEY)
            ->value('value_int');

        return $value === null ? self::DEFAULT_TRANSIT_DAYS : max(0, (int) $value);
    }
}
