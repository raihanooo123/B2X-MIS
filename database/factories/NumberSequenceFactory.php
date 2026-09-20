<?php

namespace Database\Factories;

use App\Models\NumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
class NumberSequenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key_name' => fake()->unique()->slug(2),
            'prefix' => strtoupper(fake()->lexify('???')).'-',
            'next_value' => 1,
            'padding' => 6,
        ];
    }

    /** One of the real document series named in Doc 02 §11.3. */
    public function forSeries(string $keyName, string $prefix): static
    {
        return $this->state(fn (array $attributes) => [
            'key_name' => $keyName,
            'prefix' => $prefix,
        ]);
    }
}
