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
 * @property int $restocked_base_qty
 * @property int $quarantined_base_qty
 * @property int $written_off_base_qty
 * @property int $diminished_value_minor
 * @property string|null $diminished_value_reason
 * @property string $disposition
 * @property string|null $disposition_reason
 * @property int $line_refund_net_minor
 * @property int $line_refund_tax_minor
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
        'restocked_base_qty',
        'quarantined_base_qty',
        'written_off_base_qty',
        'diminished_value_minor',
        'diminished_value_reason',
        'disposition',
        'disposition_reason',
        'inspected_by_user_id',
        'inspected_at',
        'line_refund_net_minor',
        'line_refund_tax_minor',
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
            'restocked_base_qty' => 'integer',
            'quarantined_base_qty' => 'integer',
            'written_off_base_qty' => 'integer',
            'diminished_value_minor' => 'integer',
            'line_refund_net_minor' => 'integer',
            'line_refund_tax_minor' => 'integer',
            'inspected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Rma, $this> */
    public function rma(): BelongsTo
    {
        return $this->belongsTo(Rma::class);
    }
}
