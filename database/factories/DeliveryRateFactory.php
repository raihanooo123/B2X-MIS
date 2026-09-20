<?php

namespace Database\Factories;

use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
use App\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryRate>
 */
class DeliveryRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'zone_id' => DeliveryZone::factory(),
            'method' => 'parcel',
            'weight_range' => '[0,5000)',
            'price_net_minor' => fake()->numberBetween(300, 1500),
            'per_extra_kg_minor' => null,
            'tax_class_id' => TaxClass::factory(),
            'status' => 'active',
        ];
    }

    /** A named weight band in grams, e.g. weightBand(0, 5000) for [0,5000). */
    public function weightBand(int $fromGrams, ?int $toGrams): static
    {
        return $this->state(fn (array $attributes) => [
            'weight_range' => $toGrams === null ? "[{$fromGrams},)" : "[{$fromGrams},{$toGrams})",
        ]);
    }

    public function method(string $method): static
    {
        return $this->state(fn (array $attributes) => ['method' => $method]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'draft']);
    }

    /** A closed, non-overlapping historical window. */
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
