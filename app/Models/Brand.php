<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §5.9 (amendment, signed off 2026-09-17) — brands. A shallow
 * catalogue lookup, not a full lifecycle entity like products/skus —
 * hence the three-value status set shared with categories.
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'description',
        'logo_media_id',
        'meta_title',
        'meta_description',
        'status',
    ];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
