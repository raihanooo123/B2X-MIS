<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Pack;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartLine>
 */
class CartLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'sku_id' => Sku::factory(),
            'pack_id' => Pack::factory(),
            'pack_qty' => 1,
            'pack_base_units' => 1,
            'base_qty' => 1,
        ];
    }

    /** Keeps pack arithmetic consistent for a given pack/quantity. */
    public function forPack(Pack $pack, int $packQty): static
    {
        return $this->state(fn (array $attributes) => [
            'sku_id' => $pack->sku_id,
            'pack_id' => $pack->id,
            'pack_qty' => $packQty,
            'pack_base_units' => $pack->base_units,
            'base_qty' => $packQty * $pack->base_units,
        ]);
    }
}
