<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Sku;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'sku_id' => Sku::factory(),
            'location_id' => Location::factory(),
            'movement_type' => 'goods_in',
            'base_qty' => fake()->numberBetween(1, 200),
        ];
    }

    public function dispatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'movement_type' => 'dispatch',
            'base_qty' => -abs($attributes['base_qty'] ?? fake()->numberBetween(1, 50)),
        ]);
    }

    /** Satisfies stock_movements_reason_chk. */
    public function adjustment(string $reasonCode): static
    {
        return $this->state(fn (array $attributes) => [
            'movement_type' => 'adjustment',
            'reason_code' => $reasonCode,
        ]);
    }

    public function referencing(string $type, int $id): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_type' => $type,
            'reference_id' => $id,
        ]);
    }
}
