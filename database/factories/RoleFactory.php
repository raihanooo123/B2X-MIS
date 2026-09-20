<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            // roles_code_uq is a real UNIQUE constraint over a fixed six-value
            // set — callers needing a specific one should use an explicit
            // state (admin(), salesManager()) or ->create(['code' => ...])
            // rather than relying on random uniqueness across many rows.
            'code' => fake()->randomElement([
                'admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager',
            ]),
            'name' => fake()->words(2, true),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['code' => 'admin', 'name' => 'Administrator']);
    }

    public function salesManager(): static
    {
        return $this->state(fn (array $attributes) => ['code' => 'sales_manager', 'name' => 'Sales Manager']);
    }

    public function withDiscountFloor(int $maxDiscountBp): static
    {
        return $this->state(fn (array $attributes) => ['default_max_discount_bp' => $maxDiscountBp]);
    }
}
