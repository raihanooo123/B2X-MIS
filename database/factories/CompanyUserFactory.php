<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyUser>
 */
class CompanyUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'role' => 'buyer',
            'requires_approval' => false,
            'is_default_contact' => false,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'owner']);
    }

    public function defaultContact(): static
    {
        return $this->state(fn (array $attributes) => ['is_default_contact' => true]);
    }
}
