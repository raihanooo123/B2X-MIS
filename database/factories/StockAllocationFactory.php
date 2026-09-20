<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Sku;
use App\Models\StockAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAllocation>
 */
class StockAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'sku_id' => Sku::factory(),
            'location_id' => Location::factory(),
            'base_qty' => fake()->numberBetween(1, 100),
            'status' => 'allocated',
            'allocated_at' => now(),
        ];
    }

    public function picked(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'picked']);
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'dispatched']);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'released',
            'released_at' => now(),
        ]);
    }

    public function forBatch(?Batch $batch = null): static
    {
        return $this->state(fn (array $attributes) => [
            'batch_id' => $batch?->id ?? Batch::factory(),
        ]);
    }

    public function forBin(?Bin $bin = null): static
    {
        return $this->state(fn (array $attributes) => [
            'suggested_bin_id' => $bin?->id ?? Bin::factory(),
        ]);
    }
}
