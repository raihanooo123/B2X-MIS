<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 05.4 §5.2 — one order line on a return. Price and tax are copied from the
 * order line, never re-resolved; `line_goods_net_minor` is what was charged
 * for these units (05.4 §13.5).
 *
 * @property int $id
 * @property int $rma_id
 * @property int $line_no
 * @property int $order_line_id
 * @property int $sku_id
 * @property int $pack_id
 * @property string $sku_code_snapshot
 * @property string $name_snapshot
 * @property int $requested_pack_qty
 * @property int $requested_base_qty
 * @property int $unit_price_net_e4
 * @property int $tax_rate_bp
 * @property int $line_goods_net_minor
 * @property int $received_base_qty
 * @property int|null $batch_id
 */
class RmaLine extends Model
{
    protected $fillable = [
        'rma_id',
        'line_no',
        'order_line_id',
        'sku_id',
        'pack_id',
        'sku_code_snapshot',
        'name_snapshot',
        'requested_pack_qty',
        'requested_base_qty',
        'unit_price_net_e4',
        'tax_rate_bp',
        'line_goods_net_minor',
        'received_base_qty',
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_pack_qty' => 'integer',
            'requested_base_qty' => 'integer',
            'unit_price_net_e4' => 'integer',
            'tax_rate_bp' => 'integer',
            'line_goods_net_minor' => 'integer',
            'received_base_qty' => 'integer',
        ];
    }

    /** @return BelongsTo<Rma, $this> */
    public function rma(): BelongsTo
    {
        return $this->belongsTo(Rma::class);
    }
}
