<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read model for 05.7 §10 reorder suggestions. There is no
 * reorder_suggestions table: rows come only from
 * ReorderSuggestionService::query(), which selects from a derived query
 * aliased to this model's table name. ReorderSuggestion::query() on its
 * own has nothing to read. Advisory and read-only — writes throw.
 *
 * @property string $id "{sku_id}:{location_id}"
 * @property int $sku_id
 * @property int $location_id
 * @property int|null $supplier_id
 * @property int $available_base_qty
 * @property int $incoming_base_qty
 * @property int $position_base_qty
 * @property int $reorder_point_base_qty
 * @property int $reorder_qty_base_qty
 * @property int $sold_base_qty
 * @property int $sales_window_days
 * @property int $lead_time_days
 * @property int $pack_base_units
 * @property int|null $cover_days
 * @property bool $below_reorder_point
 * @property bool $cover_short
 * @property int $suggested_base_qty
 */
class ReorderSuggestion extends Model
{
    protected $table = 'reorder_suggestions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sku_id' => 'integer',
            'location_id' => 'integer',
            'supplier_id' => 'integer',
            'available_base_qty' => 'integer',
            'incoming_base_qty' => 'integer',
            'position_base_qty' => 'integer',
            'reorder_point_base_qty' => 'integer',
            'reorder_qty_base_qty' => 'integer',
            'sold_base_qty' => 'integer',
            'sales_window_days' => 'integer',
            'lead_time_days' => 'integer',
            'pack_base_units' => 'integer',
            'cover_days' => 'integer',
            'below_reorder_point' => 'boolean',
            'cover_short' => 'boolean',
            'suggested_base_qty' => 'integer',
        ];
    }

    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): never
    {
        throw new LogicException('Reorder suggestions are computed and read-only.');
    }

    public function delete(): never
    {
        throw new LogicException('Reorder suggestions are computed and read-only.');
    }

    /** @return BelongsTo<Sku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
