<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StocktakeLine>
 */
class StocktakeLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stocktake_id' => Stocktake::factory(),
            'sku_id' => Sku::factory(),
            'counted_base_qty' => fake()->numberBetween(0, 500),
        ];
    }

    /** Reconciled at posting (04 §7.4); a nonzero variance needs a reason. */
    public function reconciled(int $expectedBaseQty, ?string $reasonCode = null): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_base_qty' => $expectedBaseQty,
            'reason_code' => $reasonCode,
        ]);
    }
}
