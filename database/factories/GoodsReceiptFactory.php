<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source' => 'manual',
            'location_id' => Location::factory(),
            'status' => 'open',
            'opened_at' => now(),
        ];
    }

    /** Satisfies goods_receipts_source_ref_chk. */
    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'purchase_order',
            'purchase_order_id' => $po->id,
            'location_id' => $po->location_id,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
}
