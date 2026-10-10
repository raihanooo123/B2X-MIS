<?php

namespace App\Domain\Credit;

use App\Models\SystemConfiguration;

/**
 * 05.2 §18.1 credit settings, from `system_configurations` (02 §2.7): a
 * company's own row wins over the global one, else the signed-off default.
 *
 *   credit.overdue_grace_days   days after due_at before on-account blocks (default 0)
 *   credit.auto_suspend_days    days overdue before the nightly suspension (default 30)
 *   credit.suspension_reason    company scope, text: debt | fraud | legal | manual
 */
final class CreditSettings
{
    public const GRACE_DAYS = 'credit.overdue_grace_days';

    public const AUTO_SUSPEND_DAYS = 'credit.auto_suspend_days';

    public const SUSPENSION_REASON = 'credit.suspension_reason';

    /** Reasons accounts may record; only `debt` still allows prepayment (05.2 §18.1). */
    public const SUSPENSION_REASONS = ['debt', 'fraud', 'legal', 'manual'];

    public function integer(string $key, int $default, ?int $companyId = null): int
    {
        $value = $companyId === null ? null : SystemConfiguration::query()
            ->where('config_key', $key)->where('scope', 'company')->where('company_id', $companyId)->value('value_int');
        $value ??= SystemConfiguration::query()->where('config_key', $key)->where('scope', 'global')->value('value_int');

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }

    /**
     * Why a suspended company is suspended. A suspension recorded before
     * reasons existed has none: it fails closed as `manual`, an all-sales
     * block, until accounts records `debt`.
     */
    public function suspensionReason(int $companyId): string
    {
        $reason = SystemConfiguration::query()->where('config_key', self::SUSPENSION_REASON)
            ->where('scope', 'company')->where('company_id', $companyId)->value('value_text');

        return is_string($reason) && in_array($reason, self::SUSPENSION_REASONS, true) ? $reason : 'manual';
    }

    public function recordSuspensionReason(int $companyId, string $reason, ?int $actorUserId): void
    {
        SystemConfiguration::query()->updateOrCreate(
            ['config_key' => self::SUSPENSION_REASON, 'scope' => 'company', 'company_id' => $companyId],
            ['value_type' => 'text', 'value_text' => $reason, 'value_int' => null, 'updated_by_user_id' => $actorUserId],
        );
    }
}
