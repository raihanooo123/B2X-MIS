<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
            'depth' => 0,
            'position' => 0,
        ];
    }

    public function childOf(int $parentId, int $depth = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parentId,
            'depth' => $depth,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'hidden']);
    }
}
