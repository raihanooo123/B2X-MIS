<?php

namespace Database\Factories;

use App\Models\SystemConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemConfiguration>
 */
class SystemConfigurationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'config_key' => fake()->unique()->slug(2),
            'scope' => 'global',
            'value_type' => 'int',
            'value_int' => fake()->numberBetween(0, 1000),
            'description' => fake()->sentence(),
        ];
    }

    /** Doc 02 §2.7 coherence CHECK: scope='company' requires company_id set. */
    public function forCompany(int $companyId): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'company',
            'company_id' => $companyId,
        ]);
    }
}
