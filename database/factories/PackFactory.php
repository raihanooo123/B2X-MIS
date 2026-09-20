<?php

namespace Database\Factories;

use App\Models\Pack;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pack>
 */
class PackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku_id' => Sku::factory(),
            'code' => 'EACH',
            'label' => 'Each',
            'pack_level' => 'each',
            'base_units' => 1,
            'is_sellable' => true,
            'is_default_sell' => true,
        ];
    }

    /** A second, non-default pack tier on the same SKU (e.g. an outer case). */
    public function outer(int $baseUnits = 12): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'OUTER'.$baseUnits,
            'label' => "Outer of {$baseUnits}",
            'pack_level' => 'outer',
            'base_units' => $baseUnits,
            'is_default_sell' => false,
        ]);
    }
}
