<?php

namespace Database\Factories;

use App\Models\B2bApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<B2bApplication>
 */
class B2bApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'address' => [
                'line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'postcode' => fake()->postcode(),
            ],
        ];
    }

    public function inReview(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'in_review']);
    }

    public function infoRequested(string $infoRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'info_requested',
            'info_request' => $infoRequest,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);
    }
}
