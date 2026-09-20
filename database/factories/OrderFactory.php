<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => 'SO-'.fake()->unique()->numerify('######'),
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'channel' => 'web',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'fulfilment_type' => 'delivery',
            'currency' => 'GBP',
            'placed_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'placed_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'paid',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    public function withDeliveryZone(?DeliveryZone $zone = null): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_zone_id' => $zone?->id ?? DeliveryZone::factory(),
        ]);
    }
}
