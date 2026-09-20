<?php

namespace App\Models;

use Database\Factories\PackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §5.6 — packs. A pack is a transaction unit, never a storage
 * unit — inventory only ever sees `base_qty`, never `pack_qty`.
 *
 * Invariant enforced only partly by the database (§5.6): every active SKU
 * has at least one sellable pack, exactly one of which `is_default_sell`
 * (the "exactly one" half is `packs_default_sell_uq`; the "at least one"
 * half is application-enforced, as no relational database expresses it).
 */
class Pack extends Model
{
    /** @use HasFactory<PackFactory> */
    use HasFactory;

    protected $fillable = [
        'sku_id',
        'code',
        'label',
        'pack_level',
        'base_units',
        'barcode',
        'gross_weight_g',
        'length_mm',
        'width_mm',
        'height_mm',
        'packs_per_layer',
        'layers_per_pallet',
        'is_sellable',
        'is_default_sell',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'base_units' => 'integer',
            'gross_weight_g' => 'integer',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'packs_per_layer' => 'integer',
            'layers_per_pallet' => 'integer',
            'is_sellable' => 'boolean',
            'is_default_sell' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
