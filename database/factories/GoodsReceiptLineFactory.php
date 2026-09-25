<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Pack;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoodsReceiptLine>
 */
class GoodsReceiptLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'client_token' => (string) Str::uuid(),
            'sku_id' => Sku::factory(),
            'pack_id' => fn (array $attributes) => Pack::factory()->state(['sku_id' => $attributes['sku_id']]),
            'pack_qty' => 1,
            'pack_base_units' => 1,
            'base_qty' => 1,
            'received_at' => now(),
        ];
    }
}
