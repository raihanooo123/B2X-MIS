<?php

namespace App\Models;

use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §8.3 — order_lines. Where pack and price snapshots live.
 * `order_lines_base_qty_chk` (base_qty = pack_qty * pack_base_units) is
 * the keystone constraint of the whole pack design, enforced by the
 * database — never write base_qty without deriving it from pack_qty and
 * pack_base_units.
 *
 * `price_list_item_id` has no FK in the doc itself — not a gap.
 */
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'line_no',
        'sku_id',
        'pack_id',
        'sku_code_snapshot',
        'name_snapshot',
        'pack_label_snapshot',
        'pack_qty',
        'pack_base_units',
        'base_qty',
        'unit_price_net_e4',
        'line_discount_minor',
        'line_spend_discount_minor',
        'line_net_minor',
        'tax_rate_bp',
        'line_tax_minor',
        'line_gross_minor',
        'price_source',
        'price_list_id',
        'price_list_item_id',
        'applied_break_qty',
        'unit_cost_e4',
        'sku_cost_id',
        'allocated_base_qty',
        'dispatched_base_qty',
        'returned_base_qty',
    ];

    protected function casts(): array
    {
        return [
            'pack_qty' => 'integer',
            'pack_base_units' => 'integer',
            'base_qty' => 'integer',
            'unit_price_net_e4' => 'integer',
            'line_discount_minor' => 'integer',
            'line_spend_discount_minor' => 'integer',
            'line_net_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'line_tax_minor' => 'integer',
            'line_gross_minor' => 'integer',
            'applied_break_qty' => 'integer',
            'unit_cost_e4' => 'integer',
            'allocated_base_qty' => 'integer',
            'dispatched_base_qty' => 'integer',
            'returned_base_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<SkuCost, $this>
     */
    public function skuCost(): BelongsTo
    {
        return $this->belongsTo(SkuCost::class);
    }

    /**
     * @return HasMany<StockAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(StockAllocation::class);
    }
}
