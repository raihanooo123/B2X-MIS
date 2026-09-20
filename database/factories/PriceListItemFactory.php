<?php

namespace Database\Factories;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'sku_id' => Sku::factory(),
            'min_base_qty' => 1,
            'unit_price_e4' => fake()->numberBetween(500, 50000),
        ];
    }

    /** A volume break above the base (min_base_qty=1) row. */
    public function breakAt(int $minBaseQty, int $unitPriceE4): static
    {
        return $this->state(fn (array $attributes) => [
            'min_base_qty' => $minBaseQty,
            'unit_price_e4' => $unitPriceE4,
        ]);
    }
}
