<?php

namespace App\Domain\Collection;

use App\Models\SystemConfiguration;

final class CollectionSettings
{
    public function integer(string $key, int $default, ?int $locationId = null): int
    {
        $value = $this->value($key, 'value_int', $locationId);

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }

    public function enabled(?int $locationId = null): bool
    {
        return (bool) ($this->value('collection.pay_at_collection.enabled', 'value_int', $locationId) ?? false);
    }

    public function pattern(int $locationId): array
    {
        $value = $this->value('collection.slot_pattern', 'value_json', $locationId);

        return is_array($value) ? $value : [];
    }

    private function value(string $key, string $column, ?int $locationId): mixed
    {
        if ($locationId !== null) {
            $local = SystemConfiguration::query()->where('config_key', $key)
                ->where('scope', 'location')->where('location_id', $locationId)->value($column);
            if ($local !== null) {
                return $local;
            }
        }

        return SystemConfiguration::query()->where('config_key', $key)
            ->where('scope', 'global')->value($column);
    }
}
