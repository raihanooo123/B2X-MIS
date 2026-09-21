<?php

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
        ];
    }

    public function variantAxis(): static
    {
        return $this->state(fn (array $attributes) => ['is_variant_axis' => true]);
    }

    public function filterable(): static
    {
        return $this->state(fn (array $attributes) => ['is_filterable' => true]);
    }
}
