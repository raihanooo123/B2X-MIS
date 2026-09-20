<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryZone>
 */
class DeliveryZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('ZONE-???')),
            'name' => fake()->city().' zone',
            'status' => 'active',
            'position' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'archived']);
    }
}
