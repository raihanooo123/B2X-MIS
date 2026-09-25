<?php

namespace App\Models;

use Database\Factories\ShipmentLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §14.6 — shipment_lines. One row per order line per shipment
 * (`shipment_lines_shipment_order_line_uq`). `sku_id` is denormalised from
 * the order line for the pick-list and reporting paths;
 * `order_lines.sku_id` stays authoritative. The batches and serials that
 * left are in the two child tables — one line is routinely split across
 * several (04 §5.3, §6.1).
 *
 * Only `created_at` — a dispatched line is not edited.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $order_line_id
 * @property int $sku_id
 * @property int $dispatched_base_qty
 */
class ShipmentLine extends Model
{
    /** @use HasFactory<ShipmentLineFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id',
        'order_line_id',
        'sku_id',
        'dispatched_base_qty',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_base_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
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
     * @return HasMany<ShipmentLineBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ShipmentLineBatch::class);
    }

    /**
     * @return HasMany<ShipmentLineSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(ShipmentLineSerial::class);
    }
}
