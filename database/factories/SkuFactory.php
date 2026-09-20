<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sku;
use App\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sku>
 */
class SkuFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku_code' => strtoupper(fake()->unique()->bothify('SKU#####')),
            'status' => 'active',
            'tax_class_id' => TaxClass::factory(),
            'base_unit' => 'each',
            'moq_base_qty' => 1,
            'order_increment_base_qty' => 1,
            'is_stock_tracked' => true,
            'tracking_mode' => 'none',
            'allocation_strategy' => 'none',
            'requires_expiry' => false,
            'allow_backorder' => false,
            'is_refundable' => true,
        ];
    }

    /** Satisfies skus_expiry_chk / skus_fefo_chk together. */
    public function batchTracked(): static
    {
        return $this->state(fn (array $attributes) => [
            'tracking_mode' => 'batch',
            'allocation_strategy' => 'fefo',
            'requires_expiry' => true,
            'shelf_life_days' => 365,
            'min_remaining_shelf_life_days' => 30,
        ]);
    }

    public function nonRefundable(string $reason = 'hygiene'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_refundable' => false,
            'non_refundable_reason' => $reason,
        ]);
    }
}
