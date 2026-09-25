<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Container>
 */
class ContainerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'container_ref' => strtoupper(fake()->unique()->bothify('????#######')),
            'container_type' => '40ft',
            'location_id' => Location::factory(),
            'status' => 'in_transit',
            'eta_date' => now()->addDays(14)->toDateString(),
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'arrived_at' => now(),
        ]);
    }
}
