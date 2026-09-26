<?php

namespace Database\Factories;

use App\Models\StocktakeLine;
use App\Models\StocktakeLineSerial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StocktakeLineSerial>
 */
class StocktakeLineSerialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stocktake_line_id' => StocktakeLine::factory(),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN########')),
            'scanned_at' => now(),
        ];
    }
}
