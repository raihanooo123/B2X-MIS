<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\StockSerial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockSerial>
 */
class StockSerialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku_id' => Sku::factory(),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN########')),
        ];
    }

    public function allocated(int $orderLineId): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'allocated',
            'order_line_id' => $orderLineId,
        ]);
    }

    public function dispatched(int $orderLineId): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dispatched',
            'order_line_id' => $orderLineId,
            'dispatched_at' => now(),
        ]);
    }

    public function expected(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'expected']);
    }
}
