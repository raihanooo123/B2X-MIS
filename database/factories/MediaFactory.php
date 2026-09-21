<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'disk' => 's3',
            'path' => 'media/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10000, 2000000),
        ];
    }

    public function forSku(int $skuId): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => null,
            'sku_id' => $skuId,
        ]);
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => 'video',
            'mime_type' => 'video/mp4',
            'path' => 'media/'.fake()->uuid().'.mp4',
        ]);
    }
}
