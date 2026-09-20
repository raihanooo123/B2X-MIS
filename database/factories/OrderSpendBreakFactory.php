<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OrderSpendBreak;
use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderSpendBreak>
 */
class OrderSpendBreakFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(3),
            'name' => fake()->words(3, true),
            'scope' => 'global',
            'min_subtotal_minor' => fake()->numberBetween(10000, 500000),
            'discount_type' => 'percentage',
            'discount_rate_bp' => fake()->numberBetween(100, 1000),
            'currency' => 'GBP',
            'status' => 'active',
        ];
    }

    /** Satisfies order_spend_breaks_coherence_chk for scope='tier'. */
    public function forTier(?PriceTier $tier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'tier',
            'price_tier_id' => $tier?->id ?? PriceTier::factory(),
        ]);
    }

    /** Satisfies order_spend_breaks_coherence_chk for scope='company'. */
    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'company',
            'company_id' => $company?->id ?? Company::factory(),
        ]);
    }

    /** Satisfies order_spend_breaks_discount_chk for discount_type='fixed'. */
    public function fixedAmount(int $amountMinor): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => 'fixed',
            'discount_amount_minor' => $amountMinor,
            'discount_rate_bp' => null,
        ]);
    }
}
