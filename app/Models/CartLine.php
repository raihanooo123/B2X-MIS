<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\CartLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §14.3 — cart_lines. Deliberately not shaped like OrderLine: no
 * price/tax/cost snapshot, since nothing is final until checkout — prices
 * are recomputed live via BulkPriceResolver on every render.
 * `pack_base_units` is refreshed by the application on a pack change, not
 * an immutable snapshot.
 *
 * @property int $cart_id
 * @property int $sku_id
 * @property int $pack_id
 * @property int $pack_qty
 * @property int $pack_base_units
 * @property int $base_qty
 */
class CartLine extends Model
{
    /** @use HasFactory<CartLineFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'cart_id',
        'sku_id',
        'pack_id',
        'pack_qty',
        'pack_base_units',
        'base_qty',
    ];

    protected function casts(): array
    {
        return [
            'pack_qty' => 'integer',
            'pack_base_units' => 'integer',
            'base_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Pack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }
}
