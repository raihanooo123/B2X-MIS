<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 02 §14.4 — one SKU and pack on a saved list, in packs and base units
 * (invariant 2). `pack_base_units` is refreshed on every write.
 *
 * @property int $id
 * @property int $saved_list_id
 * @property int $sku_id
 * @property int $pack_id
 * @property int $pack_qty
 * @property int $pack_base_units
 * @property int $base_qty
 * @property int $position
 * @property-read Sku|null $sku
 * @property-read Pack|null $pack
 */
class SavedListLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pack_qty' => 'integer', 'pack_base_units' => 'integer', 'base_qty' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<Sku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /** @return BelongsTo<Pack, $this> */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }
}
