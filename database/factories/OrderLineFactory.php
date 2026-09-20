<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Sku;
use App\Models\SkuCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    public function definition(): array
    {
        $packQty = 1;
        $packBaseUnits = 1;
        $baseQty = $packQty * $packBaseUnits;

        $unitPriceNetE4 = fake()->numberBetween(500, 50000);
        $lineNetMinor = intdiv($unitPriceNetE4 * $baseQty + 50, 100);
        $taxRateBp = 2000;
        $lineTaxMinor = intdiv($lineNetMinor * $taxRateBp + 5000, 10000);

        return [
            'order_id' => Order::factory(),
            'line_no' => 1,
            'sku_id' => Sku::factory(),
            'pack_id' => Pack::factory(),
            'sku_code_snapshot' => strtoupper(fake()->bothify('SKU#####')),
            'name_snapshot' => fake()->words(3, true),
            'pack_label_snapshot' => 'Each',
            'pack_qty' => $packQty,
            'pack_base_units' => $packBaseUnits,
            'base_qty' => $baseQty,
            'unit_price_net_e4' => $unitPriceNetE4,
            'line_net_minor' => $lineNetMinor,
            'tax_rate_bp' => $taxRateBp,
            'line_tax_minor' => $lineTaxMinor,
            'line_gross_minor' => $lineNetMinor + $lineTaxMinor,
            'price_source' => 'base',
        ];
    }

    /** Keeps pack arithmetic consistent for a given pack/quantity. */
    public function forPack(Pack $pack, int $packQty): static
    {
        $baseQty = $packQty * $pack->base_units;

        return $this->state(fn (array $attributes) => [
            'sku_id' => $pack->sku_id,
            'pack_id' => $pack->id,
            'pack_label_snapshot' => $pack->label,
            'pack_qty' => $packQty,
            'pack_base_units' => $pack->base_units,
            'base_qty' => $baseQty,
        ]);
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'allocated_base_qty' => $attributes['base_qty'],
            'dispatched_base_qty' => $attributes['base_qty'],
        ]);
    }

    /** Snapshots a cost onto the line, as placement would (§6.6). */
    public function withSkuCost(?SkuCost $skuCost = null): static
    {
        return $this->state(function (array $attributes) use ($skuCost) {
            // landed_cost_e4 is a STORED generated column — refresh() to
            // read the database-computed value rather than a stale null.
            $cost = ($skuCost ?? SkuCost::factory()->create())->refresh();

            return [
                'sku_cost_id' => $cost->id,
                'unit_cost_e4' => $cost->landed_cost_e4,
            ];
        });
    }
}
