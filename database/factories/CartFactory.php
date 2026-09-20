<?php

namespace Database\Factories;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'session_token' => fake()->unique()->uuid(),
        ];
    }

    /** A guest cart merging onto an authenticated account at login (05.13, pending). */
    public function forCompany(int $companyId, int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
    }
}
