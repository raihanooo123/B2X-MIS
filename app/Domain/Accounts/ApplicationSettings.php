<?php

namespace App\Domain\Accounts;

use App\Models\SystemConfiguration;

/**
 * The application settings in `system_configurations` (02 §2.7, §25.2,
 * §25.9). Global scope only: a rejected or pending applicant has no
 * company or location to scope by. The code defaults match the seeded
 * values.
 */
final class ApplicationSettings
{
    public const REAPPLY_COOLING_DAYS = 'applications.reapply_cooling_days';

    public const VERIFICATION_MAX_AGE_DAYS = 'applications.verification_max_age_days';

    public function reapplyCoolingDays(): int
    {
        return $this->days(self::REAPPLY_COOLING_DAYS, 90);
    }

    public function verificationMaxAgeDays(): int
    {
        return $this->days(self::VERIFICATION_MAX_AGE_DAYS, 30);
    }

    private function days(string $key, int $default): int
    {
        $value = SystemConfiguration::query()->where('config_key', $key)->where('scope', 'global')->value('value_int');

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}
