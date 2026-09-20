<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'hidden']);
    }
}
