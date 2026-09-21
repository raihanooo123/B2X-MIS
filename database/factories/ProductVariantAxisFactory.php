<?php

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductVariantAxis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariantAxis>
 */
class ProductVariantAxisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attribute_id' => Attribute::factory()->variantAxis(),
        ];
    }
}
