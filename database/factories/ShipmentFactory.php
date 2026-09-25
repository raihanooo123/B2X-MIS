<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'location_id' => Location::factory(),
            'fulfilment_type' => 'delivery',
            'status' => 'pending',
        ];
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dispatched',
            'carrier' => 'DPD',
            'tracking_number' => strtoupper(fake()->bothify('##########')),
            'picked_at' => now()->subHours(2),
            'packed_at' => now()->subHour(),
            'dispatched_at' => now(),
        ]);
    }

    public function collection(): static
    {
        return $this->state(fn (array $attributes) => ['fulfilment_type' => 'collection']);
    }
}
