<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    public function delivery(): static
    {
        return $this->state(fn (array $attributes) => ['address_type' => 'delivery']);
    }

    public function billing(): static
    {
        return $this->state(fn (array $attributes) => ['address_type' => 'billing']);
    }
}
