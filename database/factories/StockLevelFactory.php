<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Location;
use App\Models\Sku;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLevel>
 */
class StockLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku_id' => Sku::factory(),
            'location_id' => Location::factory(),
            'on_hand_base_qty' => fake()->numberBetween(0, 500),
            'allocated_base_qty' => 0,
            'incoming_base_qty' => 0,
            'reorder_point_base_qty' => 0,
            'reorder_qty_base_qty' => 0,
        ];
    }

    public function reorderable(int $point, int $qty): static
    {
        return $this->state(fn (array $attributes) => [
            'reorder_point_base_qty' => $point,
            'reorder_qty_base_qty' => $qty,
        ]);
    }

    public function forBatch(?Batch $batch = null): static
    {
        return $this->state(fn (array $attributes) => [
            'batch_id' => $batch?->id ?? Batch::factory(),
        ]);
    }
}
