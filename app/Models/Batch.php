<?php

namespace App\Models;

use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §7.5 — batches, batch & expiry tracking. No sentinel row —
 * sku_id is NOT NULL.
 *
 * Application-enforced invariants (§7.5), not database constraints —
 * these belong to app/Domain/Inventory, not this model:
 *  1. A stock_levels row may have non-NULL batch_id only if its SKU's
 *     tracking_mode is 'batch' or 'batch_and_serial'.
 *  2. A batch-tracked SKU may never hold stock against a NULL batch_id.
 *  3. requires_expiry on the SKU makes expires_on mandatory at receipt.
 *  4. A quarantined/expired/recalled batch is excluded from allocation
 *     (via the batches_fefo_idx predicate), but its stock_levels rows
 *     are never zeroed — the stock physically exists.
 *  5. min_remaining_shelf_life_days on the SKU filters candidate batches
 *     at allocation.
 *
 * `purchase_order_id` and `container_id` have no FK in the doc itself —
 * not a gap (purchase_orders/containers are Phase 3).
 */
class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use HasFactory;

    protected $fillable = [
        'sku_id',
        'batch_code',
        'supplier_batch_ref',
        'purchase_order_id',
        'container_id',
        'sku_cost_id',
        'unit_cost_e4',
        'manufactured_on',
        'expires_on',
        'best_before_on',
        'received_at',
        'status',
        'recall_reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost_e4' => 'integer',
            'manufactured_on' => 'date',
            'expires_on' => 'date',
            'best_before_on' => 'date',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<SkuCost, $this>
     */
    public function skuCost(): BelongsTo
    {
        return $this->belongsTo(SkuCost::class);
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * @return HasMany<StockAllocation, $this>
     */
    public function stockAllocations(): HasMany
    {
        return $this->hasMany(StockAllocation::class);
    }
}
