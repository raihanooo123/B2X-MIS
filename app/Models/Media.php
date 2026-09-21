<?php

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §5.8 — media. `product_id`/`sku_id` are both nullable but
 * `media_owner_chk` requires at least one — attaches to a product, a SKU,
 * or both. `variants jsonb` caches generated derivative paths so rendering
 * needs no second query.
 *
 * @property string $disk
 * @property string $path
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'sku_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'width_px',
        'height_px',
        'alt_text',
        'media_type',
        'variants',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'variants' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
