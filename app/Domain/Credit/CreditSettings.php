<?php
namespace App\Domain\Credit;
use App\Models\SystemConfiguration;
final class CreditSettings
{
    public function integer(string $key, int $default, ?int $companyId = null): int
    {
        $q = SystemConfiguration::query()->where('config_key', $key);
        $value = $companyId === null ? null : (clone $q)->where('scope', 'company')->where('company_id', $companyId)->value('value_int');
        $value ??= $q->where('scope', 'global')->value('value_int');
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
    public function suspensionReason(int $companyId): string
    {
        $reason = SystemConfiguration::query()->where('config_key', 'credit.suspension_reason')
            ->where('scope', 'company')->where('company_id', $companyId)->value('value_text');
        // Missing historical evidence fails closed. Accounts may explicitly allow debt prepay.
        return is_string($reason) ? $reason : 'manual';
    }
}
