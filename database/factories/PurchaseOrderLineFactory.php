<?php

namespace Database\Factories;

use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    public function definition(): array
    {
        $packQty = 10;
        $unitFobE4 = fake()->numberBetween(1000, 50000);
        $lineFobMinor = intdiv($unitFobE4 * $packQty + 50, 100);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'line_no' => 1,
            'sku_id' => Sku::factory(),
            'pack_id' => fn (array $attributes) => Pack::factory()->state(['sku_id' => $attributes['sku_id']]),
            'sku_code_snapshot' => strtoupper(fake()->bothify('SKU#####')),
            'pack_qty' => $packQty,
            'pack_base_units' => 1,
            'base_qty' => $packQty,
            'unit_fob_e4' => $unitFobE4,
            'line_fob_minor' => $lineFobMinor,
            'line_fob_base_minor' => $lineFobMinor,
        ];
    }

    /** Keeps purchase_order_lines_base_qty_chk satisfied for a given pack. */
    public function forPack(Pack $pack, int $packQty): static
    {
        return $this->state(function (array $attributes) use ($pack, $packQty) {
            $baseQty = $packQty * $pack->base_units;
            $lineFobMinor = intdiv($attributes['unit_fob_e4'] * $baseQty + 50, 100);

            return [
                'sku_id' => $pack->sku_id,
                'pack_id' => $pack->id,
                'pack_qty' => $packQty,
                'pack_base_units' => $pack->base_units,
                'base_qty' => $baseQty,
                'line_fob_minor' => $lineFobMinor,
                'line_fob_base_minor' => $lineFobMinor,
            ];
        });
    }
}
