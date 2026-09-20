<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAddress>
 */
class OrderAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'address_type' => 'delivery',
            'contact_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country_code' => 'GB',
        ];
    }

    public function billing(): static
    {
        return $this->state(fn (array $attributes) => ['address_type' => 'billing']);
    }
}
