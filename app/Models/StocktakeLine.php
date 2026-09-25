<?php

namespace App\Models;

use Database\Factories\StocktakeLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §14.7 — stocktake_lines, one per `(stocktake, sku, batch)`
 * (`UNIQUE NULLS NOT DISTINCT`; `batch_id IS NULL` is untracked stock).
 *
 * `expected_base_qty` is filled at posting, not at count entry — variance
 * is against the level at posting time (04 §7.4). `variance_base_qty` is
 * a stored generated column, NULL until then; never written. A posted line
 * with a nonzero variance must carry `reason_code` — enforced by the
 * posting transaction, since it depends on the parent's status.
 * `posted_movement_id` has no relation: `stock_movements` has a composite
 * key and no FKs point at it.
 *
 * @property int $id
 * @property int $stocktake_id
 * @property int $sku_id
 * @property int|null $batch_id
 * @property int $counted_base_qty
 * @property int|null $expected_base_qty
 * @property int|null $variance_base_qty
 * @property string|null $reason_code
 * @property int|null $posted_movement_id
 * @property int|null $counted_by_user_id
 * @property Carbon $counted_at
 */
class StocktakeLine extends Model
{
    /** @use HasFactory<StocktakeLineFactory> */
    use HasFactory;

    protected $fillable = [
        'stocktake_id',
        'sku_id',
        'batch_id',
        'counted_base_qty',
        'expected_base_qty',
        'reason_code',
        'posted_movement_id',
        'counted_by_user_id',
        'counted_at',
    ];

    protected function casts(): array
    {
        return [
            'counted_base_qty' => 'integer',
            'expected_base_qty' => 'integer',
            'variance_base_qty' => 'integer',
            'posted_movement_id' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Stocktake, $this>
     */
    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
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
     * @return BelongsTo<User, $this>
     */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_user_id');
    }
}
