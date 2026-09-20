<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('LOC-???')),
            'name' => fake()->city().' warehouse',
            'location_type' => 'warehouse',
            'is_sellable' => true,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    public function collection(): static
    {
        return $this->state(fn (array $attributes) => ['location_type' => 'collection']);
    }

    public function quarantine(): static
    {
        return $this->state(fn (array $attributes) => [
            'location_type' => 'quarantine',
            'is_sellable' => false,
        ]);
    }
}
