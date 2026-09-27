<?php

namespace Database\Factories;

use App\Domain\Accounts\TermsKind;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermsVersion>
 */
class TermsVersionFactory extends Factory
{
    public function definition(): array
    {
        $body = "# Terms of trade\n\n".fake()->paragraph();

        return [
            'kind' => TermsKind::Trade->value,
            'version' => fake()->unique()->numerify('v#.#.##'),
            'body_markdown' => $body,
            'body_sha256' => hash('sha256', $body),
            'effective_from' => now()->subDay(),
            'published_by_user_id' => User::factory(),
        ];
    }

    public function sale(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => TermsKind::Sale->value]);
    }
}
