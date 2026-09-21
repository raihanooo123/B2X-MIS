<?php

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Sku;
use App\Models\SkuAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuAttributeValue>
 */
class SkuAttributeValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku_id' => Sku::factory(),
            'attribute_id' => Attribute::factory(),
            'value_text' => fake()->word(),
        ];
    }

    /** A `select`-type attribute: a real attribute_values row, no free-text value. */
    public function withValue(AttributeValue $value): static
    {
        return $this->state(fn (array $attributes) => [
            'attribute_id' => $value->attribute_id,
            'attribute_value_id' => $value->id,
            'value_text' => null,
        ]);
    }

    public function numeric(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'value_text' => null,
            'value_numeric' => $value,
        ]);
    }
}
