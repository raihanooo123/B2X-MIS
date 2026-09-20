<?php

namespace App\Models;

use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §6.4 — price_list_items, the break table. Within a resolved
 * price list, the applicable row is the greatest `min_base_qty` that is
 * `<=` the line's `base_qty` — that resolution walk is domain logic for
 * 03 — Pricing Engine, not this model.
 *
 * `unit_price_e4` is ten-thousandths of a pound (CLAUDE.md money rule);
 * never mix with `_minor` values without going through Money.
 */
class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    protected $fillable = [
        'price_list_id',
        'sku_id',
        'min_base_qty',
        'unit_price_e4',
    ];

    protected function casts(): array
    {
        return [
            'min_base_qty' => 'integer',
            'unit_price_e4' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
