<?php

namespace Database\Factories;

use App\Models\Bin;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bin>
 */
class BinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'code' => strtoupper(fake()->unique()->bothify('A#-??-##')),
        ];
    }

    public function onWalkRoute(int $sequence): static
    {
        return $this->state(fn (array $attributes) => ['walk_sequence' => $sequence]);
    }
}
