<?php

namespace App\Models;

use Database\Factories\ProductVariantAxisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Doc 02 §5.7 — product_variant_axes. Which attributes distinguish the
 * variants of one `product_type = 'variant'` product (§5.2).
 */
class ProductVariantAxis extends Pivot
{
    /** @use HasFactory<ProductVariantAxisFactory> */
    use HasFactory;

    protected $table = 'product_variant_axes';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'attribute_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
