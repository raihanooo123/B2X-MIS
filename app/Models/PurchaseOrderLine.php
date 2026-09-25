<?php

namespace App\Models;

use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 05.7 §5 — purchase_order_lines. `base_qty = pack_qty *
 * pack_base_units` is a CHECK. `received_base_qty` is written only by the
 * goods-in transaction (04 §7.1). `variance_reason` is the closed list of
 * 05.5 §4.4.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_no
 * @property int $sku_id
 * @property int $pack_id
 * @property string $sku_code_snapshot
 * @property int $pack_qty
 * @property int $pack_base_units
 * @property int $base_qty
 * @property int $received_base_qty
 * @property int $unit_fob_e4
 * @property int $line_fob_minor
 * @property int $line_fob_base_minor
 * @property string|null $variance_reason
 */
class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'line_no',
        'sku_id',
        'pack_id',
        'sku_code_snapshot',
        'pack_qty',
        'pack_base_units',
        'base_qty',
        'received_base_qty',
        'unit_fob_e4',
        'line_fob_minor',
        'line_fob_base_minor',
        'line_weight_g',
        'line_volume_cm3',
        'expected_at',
        'variance_reason',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'pack_qty' => 'integer',
            'pack_base_units' => 'integer',
            'base_qty' => 'integer',
            'received_base_qty' => 'integer',
            'unit_fob_e4' => 'integer',
            'line_fob_minor' => 'integer',
            'line_fob_base_minor' => 'integer',
            'line_weight_g' => 'integer',
            'line_volume_cm3' => 'integer',
            'expected_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Pack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }
}
