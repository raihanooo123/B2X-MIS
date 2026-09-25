<?php

namespace App\Domain\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Models\Sku;
use App\Models\SystemConfiguration;
use Carbon\CarbonImmutable;

/**
 * Expiry capture at goods-in (05.5 §4.3):
 *
 *   - Where `shelf_life_days` is set, expiry is pre-filled from the
 *     receipt date and stays editable. Most receipts are fresh stock.
 *   - An expiry in the past, or beyond the sanity horizon, warns and
 *     needs confirmation. A mistyped year is the common error; a past
 *     date can be legitimate clearance stock (05.5 §12).
 *
 * The horizon is `goods_in.expiry_horizon_days` (02 §23.4), resolved
 * location → global → 3650 days (05.5 open question 4: "10 years
 * assumed"). Dates are the warehouse's calendar day, Europe/London, not
 * UTC: a receipt booked at 00:30 BST is dated today.
 */
final class ExpiryPolicy
{
    public const HORIZON_KEY = 'goods_in.expiry_horizon_days';

    public const DEFAULT_HORIZON_DAYS = 3650;

    public const TIMEZONE = 'Europe/London';

    public const WARNING_PAST = 'expiry_in_past';

    public const WARNING_BEYOND_HORIZON = 'expiry_beyond_horizon';

    /** The warehouse's calendar day for a moment. */
    public static function receiptDate(?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /** Null when the SKU captures no expiry or has no shelf life set. */
    public function prefill(Sku $sku, CarbonImmutable $receiptDate): ?CarbonImmutable
    {
        if (! TrackingMode::from($sku->tracking_mode)->tracksBatch() || $sku->shelf_life_days === null) {
            return null;
        }

        return $receiptDate->addDays($sku->shelf_life_days);
    }

    /**
     * @return list<string> WARNING_* codes; empty means no confirmation needed
     */
    public function warnings(CarbonImmutable $expiresOn, CarbonImmutable $receiptDate, int $horizonDays): array
    {
        // Calendar dates, compared as Y-m-d strings: an expiry is a date,
        // and comparing instants across UTC and Europe/London would move it.
        $warnings = [];
        $expiry = $expiresOn->toDateString();

        if ($expiry < $receiptDate->toDateString()) {
            $warnings[] = self::WARNING_PAST;
        }

        if ($expiry > $receiptDate->addDays($horizonDays)->toDateString()) {
            $warnings[] = self::WARNING_BEYOND_HORIZON;
        }

        return $warnings;
    }

    public function horizonDays(?int $locationId): int
    {
        $rows = SystemConfiguration::query()
            ->where('config_key', self::HORIZON_KEY)
            ->where(fn ($q) => $q->where('scope', 'global')->when($locationId !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope', 'location')->where('location_id', $locationId))))
            ->get(['scope', 'value_int'])
            ->keyBy('scope');

        return ($rows->get('location') ?? $rows->get('global'))->value_int ?? self::DEFAULT_HORIZON_DAYS;
    }
}
