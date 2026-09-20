<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(3),
            'name' => fake()->words(3, true),
            'scope' => 'base',
            'currency' => 'GBP',
            'status' => 'active',
        ];
    }

    /** Satisfies price_lists_coherence_chk for scope='tier'. */
    public function forTier(?PriceTier $tier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'tier',
            'price_tier_id' => $tier?->id ?? PriceTier::factory(),
        ]);
    }

    /** Satisfies price_lists_coherence_chk for scope='company'. */
    public function forCompany(?Company $company = null, bool $hasContract = false): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'company',
            'company_id' => $company?->id ?? Company::factory(),
            'has_contract' => $hasContract,
        ]);
    }

    /** Satisfies price_lists_coherence_chk for scope='promotion'. */
    public function forPromotion(int $promotionId): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'promotion',
            'promotion_id' => $promotionId,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'draft']);
    }
}
