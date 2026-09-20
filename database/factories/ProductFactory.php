<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_type' => 'simple',
            'name' => fake()->unique()->words(3, true),
            'slug' => fake()->unique()->slug(),
            'primary_category_id' => Category::factory(),
            'status' => 'active',
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'is_featured' => false,
            'completeness_score' => fake()->numberBetween(0, 100),
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function variantParent(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => 'variant',
        ]);
    }

    public function withBrand(?Brand $brand = null): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_id' => $brand?->id ?? Brand::factory(),
        ]);
    }
}
