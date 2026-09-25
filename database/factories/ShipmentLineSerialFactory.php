<?php

namespace Database\Factories;

use App\Models\ShipmentLine;
use App\Models\ShipmentLineSerial;
use App\Models\StockSerial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentLineSerial>
 */
class ShipmentLineSerialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipment_line_id' => ShipmentLine::factory(),
            'serial_id' => StockSerial::factory(),
        ];
    }
}
