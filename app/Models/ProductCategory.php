<?php

namespace App\Models;

use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Doc 02 §5.9 (amendment, signed off 2026-09-17) — product_categories.
 * Secondary/cross-listing category membership; a product's primary
 * category stays the direct `products.primary_category_id` FK (§5.4).
 */
class ProductCategory extends Pivot
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory;

    protected $table = 'product_categories';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'category_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
