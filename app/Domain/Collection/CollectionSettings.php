<?php

namespace App\Domain\Collection;

use App\Models\SystemConfiguration;

/**
 * 05.6 §7A.12 — the collection keys in `system_configurations` (02 §2.7):
 * the location's own row first, then the global row, then the default
 * here. `bool` keys are stored in `value_int` as 0 or 1.
 */
final class CollectionSettings
{
    public const SLOT_PATTERN = 'collection.slot_pattern';

    public const HORIZON_DAYS = 'collection.booking_horizon_days';

    public const MIN_NOTICE_MINUTES = 'collection.min_notice_minutes';

    public const FREE_THRESHOLD = 'collection.free_threshold_net_minor';

    public const CASH_ENABLED = 'collection.pay_at_collection.enabled';

    public const CASH_LIMIT = 'collection.pay_at_collection.max_order_gross_minor';

    public const CASH_GRACE_MINUTES = 'collection.pay_at_collection.grace_minutes';

    public const NO_SHOW_LIMIT = 'collection.pay_at_collection.no_show_limit';

    public const NO_SHOW_WINDOW_DAYS = 'collection.pay_at_collection.no_show_window_days';

    public function horizonDays(int $locationId): int
    {
        return $this->integer(self::HORIZON_DAYS, 14, $locationId);
    }

    public function minNoticeMinutes(int $locationId): int
    {
        return $this->integer(self::MIN_NOTICE_MINUTES, 120, $locationId);
    }

    public function freeThresholdNetMinor(int $locationId): int
    {
        return $this->integer(self::FREE_THRESHOLD, 20000, $locationId);
    }

    public function cashEnabled(int $locationId): bool
    {
        return $this->integer(self::CASH_ENABLED, 0, $locationId) === 1;
    }

    /** Gross, because cash is paid gross (§7A.3). Default £300. */
    public function cashLimitGrossMinor(int $locationId): int
    {
        return $this->integer(self::CASH_LIMIT, 30000, $locationId);
    }

    public function graceMinutes(int $locationId): int
    {
        return $this->integer(self::CASH_GRACE_MINUTES, 60, $locationId);
    }

    /** Global only (§7A.12). */
    public function noShowLimit(): int
    {
        return max(1, $this->integer(self::NO_SHOW_LIMIT, 2));
    }

    /** Global only (§7A.12). */
    public function noShowWindowDays(): int
    {
        return $this->integer(self::NO_SHOW_WINDOW_DAYS, 365);
    }

    /**
     * Location-scoped only: a location with no pattern has no slots.
     *
     * @return array<string, mixed>
     */
    public function pattern(int $locationId): array
    {
        $value = SystemConfiguration::query()->where('config_key', self::SLOT_PATTERN)
            ->where('scope', 'location')->where('location_id', $locationId)->value('value_json');

        return is_array($value) ? $value : [];
    }

    private function integer(string $key, int $default, ?int $locationId = null): int
    {
        $value = null;
        if ($locationId !== null) {
            $value = SystemConfiguration::query()->where('config_key', $key)
                ->where('scope', 'location')->where('location_id', $locationId)->value('value_int');
        }
        $value ??= SystemConfiguration::query()->where('config_key', $key)->where('scope', 'global')->value('value_int');

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}
