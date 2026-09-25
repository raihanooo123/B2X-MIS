<?php

namespace App\Models;

use Database\Factories\ShipmentLineSerialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §14.6 — shipment_line_serials. The specific serials that left on
 * a shipment line (04 §6.1).
 *
 * @property int $id
 * @property int $shipment_line_id
 * @property int $serial_id
 */
class ShipmentLineSerial extends Model
{
    /** @use HasFactory<ShipmentLineSerialFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'shipment_line_id',
        'serial_id',
    ];

    /**
     * @return BelongsTo<ShipmentLine, $this>
     */
    public function shipmentLine(): BelongsTo
    {
        return $this->belongsTo(ShipmentLine::class);
    }

    /**
     * @return BelongsTo<StockSerial, $this>
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(StockSerial::class, 'serial_id');
    }
}
