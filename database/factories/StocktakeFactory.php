<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Stocktake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stocktake>
 */
class StocktakeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'status' => 'open',
            'is_blind' => false,
            'started_at' => now(),
        ];
    }

    public function blind(): static
    {
        return $this->state(fn (array $attributes) => ['is_blind' => true]);
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }
}
