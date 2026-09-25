<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\ShipmentLine;
use App\Models\ShipmentLineBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentLineBatch>
 */
class ShipmentLineBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipment_line_id' => ShipmentLine::factory(),
            'batch_id' => Batch::factory(),
            'base_qty' => 1,
        ];
    }
}
