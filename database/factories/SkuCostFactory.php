<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\SkuCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuCost>
 */
class SkuCostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku_id' => Sku::factory(),
            'source' => 'manual',
            'currency' => 'GBP',
            'fob_e4' => fake()->numberBetween(1000, 20000),
            'freight_e4' => fake()->numberBetween(0, 2000),
            'duty_e4' => fake()->numberBetween(0, 1000),
            'other_e4' => 0,
            'is_provisional' => false,
            'valid_from' => now(),
        ];
    }

    /** Satisfies sku_costs_fx_chk: non-GBP requires fx_rate_e4. */
    public function inCurrency(string $currency, int $fxRateE4): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => $currency,
            'fx_rate_e4' => $fxRateE4,
        ]);
    }

    public function fromPurchaseOrder(int $purchaseOrderId): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'purchase_order',
            'purchase_order_id' => $purchaseOrderId,
        ]);
    }

    public function provisional(): static
    {
        return $this->state(fn (array $attributes) => ['is_provisional' => true]);
    }

    /** A historical cost row, superseded by a later valid_from. */
    public function asOf(\DateTimeInterface $validFrom): static
    {
        return $this->state(fn (array $attributes) => ['valid_from' => $validFrom]);
    }
}
