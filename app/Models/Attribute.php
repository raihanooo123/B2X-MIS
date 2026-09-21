<?php

namespace App\Models;

use Database\Factories\AttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §5.7 — attributes. One table serves two uses:
 * `is_variant_axis` (product variant selectors) and `is_filterable`
 * (catalogue facets) are independent flags, not mutually exclusive.
 */
class Attribute extends Model
{
    /** @use HasFactory<AttributeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'data_type',
        'unit',
        'is_variant_axis',
        'is_filterable',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_variant_axis' => 'boolean',
            'is_filterable' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
