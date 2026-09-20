<?php

namespace Database\Factories;

use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceTier>
 */
class PriceTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'position' => 0,
            'is_default' => false,
        ];
    }

    /**
     * Only one tier may be default system-wide (price_tiers_default_uq) —
     * callers are responsible for not creating a second one.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
