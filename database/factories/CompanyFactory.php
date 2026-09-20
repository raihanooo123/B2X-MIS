<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_code' => strtoupper(fake()->unique()->bothify('ACC-####??')),
            'name' => fake()->company(),
            'trading_name' => null,
            'vat_number' => null,
            'registration_number' => null,
            'status' => 'approved',
            'payment_terms' => fake()->randomElement(['prepay', 'net7', 'net14', 'net30', 'net60']),
            'tax_exempt' => false,
            'price_display_mode' => 'net',
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'applied',
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);
    }

    public function withRep(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_rep_user_id' => $user?->id ?? User::factory(),
        ]);
    }
}
