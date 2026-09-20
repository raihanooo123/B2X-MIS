<?php

namespace Database\Factories;

use App\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxClass>
 */
class TaxClassFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'xero_tax_type' => null,
        ];
    }

    /** Doc 02 §6.5 seed: standard 2000bp. */
    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'standard',
            'name' => 'Standard rate',
        ]);
    }

    /** Doc 02 §6.5 seed: zero 0bp. */
    public function zero(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'zero',
            'name' => 'Zero rate',
        ]);
    }

    /** Doc 02 §6.5 seed: reduced 500bp. */
    public function reduced(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'reduced',
            'name' => 'Reduced rate',
        ]);
    }
}
