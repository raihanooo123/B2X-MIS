<?php

namespace App\Models;

use Database\Factories\StockLevelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Doc 02 §7.3 — stock_levels. A rebuildable projection over
 * stock_movements (§7.2) — never write on_hand_base_qty or
 * allocated_base_qty directly from application code outside the
 * transaction that also appends the corresponding stock_movements row;
 * that write discipline is domain logic for app/Domain/Inventory, not
 * this model.
 *
 * No surrogate id: the natural key (sku_id, location_id, batch_id) is
 * the row's actual identity (§7.3's "Postgres argument" — heap storage
 * gives a surrogate key no locality advantage, and nothing foreign-keys
 * to this table). Because of that, `$model->save()` on an
 * already-persisted instance cannot locate its row — update via
 * `where(['sku_id' => ..., 'location_id' => ..., 'batch_id' => ...])`
 * or `updateOrCreate()` on that same triple, never by a single key.
 *
 * `fresh()`/`refresh()` are overridden to throw rather than silently
 * return an arbitrary row: with `$primaryKey = null`, Eloquent's default
 * implementation builds `WHERE NULL = NULL`, which Postgres (and the
 * query builder) treats as no filter at all — verified live, it returns
 * whichever row the query planner picks first, not this instance's row.
 * Use `reload()` below instead.
 *
 * `available_base_qty` is a STORED generated column and is deliberately
 * not listed as fillable — Postgres rejects writes to it.
 *
 * @property int $sku_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property int $allocated_base_qty
 * @property int $available_base_qty
 * @property int $incoming_base_qty
 */
class StockLevel extends Model
{
    /** @use HasFactory<StockLevelFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = true;

    const CREATED_AT = null;

    protected $fillable = [
        'sku_id',
        'location_id',
        'batch_id',
        'on_hand_base_qty',
        'allocated_base_qty',
        'incoming_base_qty',
        'reorder_point_base_qty',
        'reorder_qty_base_qty',
        'version',
        'last_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_base_qty' => 'integer',
            'allocated_base_qty' => 'integer',
            'available_base_qty' => 'integer',
            'incoming_base_qty' => 'integer',
            'reorder_point_base_qty' => 'integer',
            'reorder_qty_base_qty' => 'integer',
            'version' => 'integer',
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
     * Re-queries this row by its actual identity (sku_id, location_id,
     * batch_id) — the correct replacement for fresh()/refresh() on a
     * model with no single-column key. Returns null if the row no
     * longer exists.
     */
    public function reload(): ?self
    {
        return static::identity($this->sku_id, $this->location_id, $this->batch_id)->first();
    }

    /**
     * The only correct way to look up one stock_levels row: by its full
     * natural key, batch_id included (NULL means "not batch-tracked").
     *
     * @return Builder<static>
     */
    public static function identity(int $skuId, int $locationId, ?int $batchId = null): Builder
    {
        return static::query()
            ->where('sku_id', $skuId)
            ->where('location_id', $locationId)
            ->when($batchId === null, fn (Builder $q) => $q->whereNull('batch_id'), fn (Builder $q) => $q->where('batch_id', $batchId));
    }

    /**
     * @param  array<int, string>  $with
     */
    public function fresh($with = []): never
    {
        throw new LogicException('StockLevel has no single-column key — call reload() instead of fresh().');
    }

    public function refresh(): never
    {
        throw new LogicException('StockLevel has no single-column key — call reload() instead of refresh().');
    }
}
