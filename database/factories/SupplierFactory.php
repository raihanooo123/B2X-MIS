<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SUP-####')),
            'name' => fake()->company(),
            'country_code' => 'GB',
            'default_currency' => 'GBP',
            'default_incoterm' => 'FOB',
            'status' => 'active',
        ];
    }
}
