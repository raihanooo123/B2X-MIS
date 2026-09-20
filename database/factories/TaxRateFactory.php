<?php

namespace Database\Factories;

use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tax_class_id' => TaxClass::factory(),
            'country_code' => 'GB',
            'region' => null,
            'rate_bp' => fake()->randomElement([0, 500, 2000]),
        ];
    }

    /** Doc 02 §6.5 seed: standard rate, 2000bp. */
    public function standard(): static
    {
        return $this->state(fn (array $attributes) => ['rate_bp' => 2000]);
    }

    /** Doc 02 §6.5 seed: zero rate, 0bp. */
    public function zeroRated(): static
    {
        return $this->state(fn (array $attributes) => ['rate_bp' => 0]);
    }

    /** Doc 02 §6.5 seed: reduced rate, 500bp. */
    public function reduced(): static
    {
        return $this->state(fn (array $attributes) => ['rate_bp' => 500]);
    }

    /**
     * A closed, non-overlapping historical window — use alongside another
     * rate on the same tax class/country/region without tripping
     * `tax_rates_no_overlap`.
     */
    public function forPeriod(\DateTimeInterface $from, \DateTimeInterface $to): static
    {
        return $this->state(fn (array $attributes) => [
            'validity' => sprintf(
                '[%s,%s)',
                $from->format('Y-m-d H:i:sP'),
                $to->format('Y-m-d H:i:sP')
            ),
        ]);
    }
}
