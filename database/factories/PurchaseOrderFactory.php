<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.fake()->unique()->numerify('######'),
            'supplier_id' => Supplier::factory(),
            'location_id' => Location::factory(),
            'status' => 'confirmed',
            'incoterm' => 'FOB',
            'currency' => 'GBP',
            'ordered_at' => now()->subDays(30),
            'expected_at' => now()->addDays(7)->toDateString(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'draft']);
    }

    /** Satisfies purchase_orders_fx_chk. */
    public function inCurrency(string $currency, int $fxRateE4): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => $currency,
            'fx_rate_e4' => $fxRateE4,
        ]);
    }
}
