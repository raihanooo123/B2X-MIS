<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 05.10 §2.4 — what one cancellation took off one order line: whole packs,
 * and its exact share of the line's money (cumulative rounding, §2.2).
 * Immutable (`order_cancellation_lines_immutable`).
 *
 * @property int $id
 * @property int $order_cancellation_id
 * @property int $order_line_id
 * @property int $cancelled_pack_qty
 * @property int $cancelled_base_qty
 * @property int $line_net_minor
 * @property int $line_tax_minor
 * @property int $line_gross_minor
 */
class OrderCancellationLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_cancellation_id', 'order_line_id', 'cancelled_pack_qty', 'cancelled_base_qty',
        'line_net_minor', 'line_tax_minor', 'line_gross_minor',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_pack_qty' => 'integer',
            'cancelled_base_qty' => 'integer',
            'line_net_minor' => 'integer',
            'line_tax_minor' => 'integer',
            'line_gross_minor' => 'integer',
        ];
    }
}
