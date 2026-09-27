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

    /**
     * 02 §25.2: a rejection always carries its outcome
     * (`b2b_applications_rejection_outcome_chk`). Remediable by default;
     * pass a date for one still in its cooling period.
     */
    public function rejected(?\DateTimeInterface $reapplyAfter = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'reviewed_at' => now(),
            'review_note' => 'Rejected in a test.',
            'rejection_category' => 'other',
            'rejection_remediable' => $reapplyAfter === null,
            'reapply_after' => $reapplyAfter,
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
