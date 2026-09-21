<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditHold>
 */
class CreditHoldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'order_id' => Order::factory(),
            'amount_minor' => fake()->numberBetween(1000, 500000),
            'status' => 'held',
            'held_at' => now(),
        ];
    }

    public function invoiced(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'invoiced']);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'released',
            'released_at' => now(),
        ]);
    }
}
