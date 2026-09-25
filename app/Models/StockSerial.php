<?php

namespace App\Models;

use Database\Factories\StockSerialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §7.6 — stock_serials. Uniqueness is scoped `(sku_id,
 * serial_number)`, not global — two manufacturers can legitimately issue
 * the same serial string.
 *
 * @property int $id
 * @property int $sku_id
 * @property string $serial_number
 * @property int|null $batch_id
 * @property int|null $location_id
 * @property int|null $bin_id
 * @property string $status
 * @property int|null $order_line_id
 * @property int|null $received_movement_id
 * @property Carbon|null $received_at
 */
class StockSerial extends Model
{
    /** @use HasFactory<StockSerialFactory> */
    use HasFactory;

    protected $fillable = [
        'sku_id',
        'serial_number',
        'batch_id',
        'location_id',
        'bin_id',
        'status',
        'order_line_id',
        'rma_line_id',
        'received_movement_id',
        'dispatched_movement_id',
        'warranty_expires_on',
        'received_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'warranty_expires_on' => 'date',
            'received_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * The `(sku_id, location_id, batch_id)` identity `stock_serials_pick_idx`
     * is built for — 04 §6.1's reservation query filters on exactly this
     * triple before ordering by `id ASC` and locking. Mirrors
     * `StockLevel::identity()`'s NULL-safe equality (`batch_id IS NULL`
     * meaning "untracked" must use `whereNull`, not `= NULL`, which is
     * never true in SQL) — `location_id` is nullable here too (unlike
     * `StockLevel`, where it is `NOT NULL`), so it gets the same treatment.
     *
     * @return Builder<static>
     */
    public static function identity(int $skuId, ?int $locationId = null, ?int $batchId = null): Builder
    {
        return static::query()
            ->where('sku_id', $skuId)
            ->when(
                $locationId === null,
                fn (Builder $q) => $q->whereNull('location_id'),
                fn (Builder $q) => $q->where('location_id', $locationId),
            )
            ->when(
                $batchId === null,
                fn (Builder $q) => $q->whereNull('batch_id'),
                fn (Builder $q) => $q->where('batch_id', $batchId),
            );
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Bin, $this>
     */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(Bin::class);
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
