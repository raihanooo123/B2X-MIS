<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryClosure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryClosure>
 */
class CategoryClosureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ancestor_id' => Category::factory(),
            'descendant_id' => Category::factory(),
            'depth' => 1,
        ];
    }

    /** Every category also closes over itself at depth 0. */
    public function selfReference(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'ancestor_id' => $categoryId,
            'descendant_id' => $categoryId,
            'depth' => 0,
        ]);
    }
}
