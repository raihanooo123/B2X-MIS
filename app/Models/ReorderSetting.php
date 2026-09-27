<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read model for 05.7 §10.7 reorder settings: one row per active,
 * stock-tracked SKU at each sellable location. There is no
 * reorder_settings table — rows come only from
 * ReorderSettingsService::query(), and changes go through
 * ReorderSettingsService::set(). Direct writes throw.
 *
 * @property string $id "{sku_id}:{location_id}"
 * @property int $sku_id
 * @property int $location_id
 * @property int $available_base_qty
 * @property int $incoming_base_qty
 * @property int $reorder_point_base_qty
 * @property int $reorder_qty_base_qty
 */
class ReorderSetting extends Model
{
    protected $table = 'reorder_settings';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sku_id' => 'integer',
            'location_id' => 'integer',
            'available_base_qty' => 'integer',
            'incoming_base_qty' => 'integer',
            'reorder_point_base_qty' => 'integer',
            'reorder_qty_base_qty' => 'integer',
        ];
    }

    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): never
    {
        throw new LogicException('Reorder settings change only through ReorderSettingsService::set().');
    }

    public function delete(): never
    {
        throw new LogicException('Reorder settings change only through ReorderSettingsService::set().');
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
}
