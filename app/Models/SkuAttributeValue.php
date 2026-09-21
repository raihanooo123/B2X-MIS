<?php

namespace App\Models;

use Database\Factories\SkuAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Doc 02 §5.7 — sku_attribute_values. `attribute_value_id` is nullable —
 * a `data_type` of `text`/`integer`/`decimal`/`boolean` (rather than
 * `select`) has no matching `attribute_values` row and stores its value
 * directly in `value_text`/`value_numeric` instead.
 */
class SkuAttributeValue extends Pivot
{
    /** @use HasFactory<SkuAttributeValueFactory> */
    use HasFactory;

    protected $table = 'sku_attribute_values';

    public $timestamps = false;

    protected $fillable = [
        'sku_id',
        'attribute_id',
        'attribute_value_id',
        'value_text',
        'value_numeric',
    ];

    protected function casts(): array
    {
        return [
            'value_numeric' => 'decimal:4',
        ];
    }
}
