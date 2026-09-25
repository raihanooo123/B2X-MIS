<?php

namespace App\Models;

use Database\Factories\ShipmentLineBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §14.6 — shipment_line_batches. How much of a shipment line came
 * from each batch. Read in reverse (`shipment_line_batches_batch_idx`) by
 * the recall trace and by 05.4's returned-batch recovery.
 *
 * @property int $id
 * @property int $shipment_line_id
 * @property int $batch_id
 * @property int $base_qty
 */
class ShipmentLineBatch extends Model
{
    /** @use HasFactory<ShipmentLineBatchFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'shipment_line_id',
        'batch_id',
        'base_qty',
    ];

    protected function casts(): array
    {
        return [
            'base_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShipmentLine, $this>
     */
    public function shipmentLine(): BelongsTo
    {
        return $this->belongsTo(ShipmentLine::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
