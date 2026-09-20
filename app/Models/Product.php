<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Doc 02 §5.4 — products. `skus` is the only stockable, priceable,
 * sellable entity (§5.2) — nothing in pricing, inventory or ordering
 * references `products.id` directly.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /** `search_vector` is a STORED generated column and is deliberately not listed — Postgres rejects writes to it. */
    protected $fillable = [
        'public_id',
        'product_type',
        'name',
        'slug',
        'brand_id',
        'primary_category_id',
        'status',
        'short_description',
        'description',
        'rrp_minor',
        'origin_country',
        'hs_code',
        'is_featured',
        'meta_title',
        'meta_description',
        'specifications',
        'completeness_score',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'rrp_minor' => 'integer',
            'is_featured' => 'boolean',
            'specifications' => 'array',
            'completeness_score' => 'integer',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Secondary/cross-listing category membership (§5.9). The primary
     * category is `primaryCategory()` above, not this relation.
     *
     * @return BelongsToMany<Category, $this, ProductCategory, 'pivot'>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories')
            ->using(ProductCategory::class)
            ->withPivot(['position']);
    }

    /**
     * @return HasMany<Sku, $this>
     */
    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }
}
