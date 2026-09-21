<?php

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttributeValue>
 */
class AttributeValueFactory extends Factory
{
    public function definition(): array
    {
        $value = fake()->unique()->word();

        return [
            'attribute_id' => Attribute::factory(),
            'value' => $value,
            'slug' => Str::slug($value),
        ];
    }

    public function withSwatch(string $hex): static
    {
        return $this->state(fn (array $attributes) => ['swatch_hex' => $hex]);
    }
}
