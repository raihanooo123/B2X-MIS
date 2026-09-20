<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku_id' => Sku::factory(),
            'batch_code' => strtoupper(fake()->unique()->bothify('BATCH-#####')),
            'status' => 'active',
            'received_at' => now(),
        ];
    }

    /** Satisfies batches_dates_chk. */
    public function expiring(\DateTimeInterface $manufacturedOn, \DateTimeInterface $expiresOn): static
    {
        return $this->state(fn (array $attributes) => [
            'manufactured_on' => $manufacturedOn->format('Y-m-d'),
            'expires_on' => $expiresOn->format('Y-m-d'),
        ]);
    }

    public function quarantined(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'quarantined']);
    }

    public function recalled(string $reference): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'recalled',
            'recall_reference' => $reference,
        ]);
    }
}
