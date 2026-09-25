<?php

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentLine>
 */
class ShipmentLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'order_line_id' => OrderLine::factory(),
            // Denormalised from the order line (02 §14.6), so kept in step with it.
            'sku_id' => fn (array $attributes) => OrderLine::query()->findOrFail($attributes['order_line_id'])->sku_id,
            'dispatched_base_qty' => 1,
        ];
    }
}
