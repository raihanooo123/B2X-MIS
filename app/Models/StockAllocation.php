<?php

namespace App\Models;

use Database\Factories\StockAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §7.4 — stock_allocations. Allocation reserves, dispatch
 * consumes (§7.2 principle 3) — never write allocated_base_qty on
 * stock_levels without a corresponding row here in the same
 * transaction; the FOR UPDATE locking and lock ordering that makes this
 * safe under concurrency (CLAUDE.md invariant 6) is domain logic for
 * app/Domain/Inventory, not this model.
 *
 * `suggested_bin_id` is advisory and never locked (§7.4) — a picker
 * taking goods from a different bin is not an error.
 *
 * @property int $id
 * @property int $order_line_id
 * @property int $sku_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property int $base_qty
 * @property string $status
 */
class StockAllocation extends Model
{
    /** @use HasFactory<StockAllocationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_line_id',
        'sku_id',
        'location_id',
        'batch_id',
        'suggested_bin_id',
        'base_qty',
        'status',
        'allocated_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'base_qty' => 'integer',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Bin, $this>
     */
    public function suggestedBin(): BelongsTo
    {
        return $this->belongsTo(Bin::class, 'suggested_bin_id');
    }
}
