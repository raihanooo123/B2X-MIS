<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use App\Models\DeliveryZonePostcode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryZonePostcode>
 */
class DeliveryZonePostcodeFactory extends Factory
{
    public function definition(): array
    {
        $from = fake()->numberBetween(1, 20);

        return [
            'delivery_zone_id' => DeliveryZone::factory(),
            'area' => strtoupper(fake()->unique()->lexify('??')),
            'district_from' => $from,
            'district_to' => $from + fake()->numberBetween(0, 5),
        ];
    }
}
