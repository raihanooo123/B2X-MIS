<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05.4 §5.1 — a return. `company_id` NULL is a consumer order's return
 * (05.4 §13): no restocking fee (`rmas_consumer_fee_chk`), and a statutory
 * cancellation carries when the customer told us and the possession day
 * its window ran from (`rmas_cancellation_chk`).
 *
 * @property int $id
 * @property string $public_id
 * @property string $rma_number
 * @property int|null $company_id
 * @property int $order_id
 * @property int|null $requested_by_user_id
 * @property string $status
 * @property string $return_reason
 * @property string|null $return_method
 * @property string $carriage_payer
 * @property int $goods_net_minor
 * @property Carbon|null $cancellation_notified_at
 * @property Carbon|null $possession_on
 * @property string|null $possession_basis
 * @property Carbon|null $refund_due_on
 * @property Carbon|null $goods_sent_at
 * @property Carbon|null $received_at
 * @property int|null $handled_by_user_id
 * @property int|null $refund_payment_id
 * @property string|null $internal_note
 * @property string|null $reason_detail
 * @property string|null $resolution_type
 * @property int $refund_net_minor
 * @property int $refund_tax_minor
 * @property int $refund_gross_minor
 * @property int $delivery_refund_net_minor
 * @property int $delivery_refund_tax_minor
 * @property int|null $credit_note_id
 * @property Carbon|null $inspected_at
 * @property Carbon|null $resolved_at
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $return_by_date
 */
class Rma extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'rma_number',
        'company_id',
        'order_id',
        'requested_by_user_id',
        'handled_by_user_id',
        'status',
        'return_reason',
        'reason_detail',
        'resolution_type',
        'return_method',
        'carriage_payer',
        'goods_net_minor',
        'restocking_rate_bp',
        'restocking_minimum_minor',
        'restocking_fee_minor',
        'carriage_recharge_minor',
        'cancellation_notified_at',
        'possession_on',
        'possession_basis',
        'goods_sent_at',
        'refund_due_on',
        'received_at',
        'internal_note',
        'inspected_at',
        'resolved_at',
        'refund_net_minor',
        'refund_tax_minor',
        'refund_gross_minor',
        'delivery_refund_net_minor',
        'delivery_refund_tax_minor',
        'credit_note_id',
        'refund_payment_id',
        'requested_at',
        'approved_at',
        'return_by_date',
    ];

    protected function casts(): array
    {
        return [
            'goods_net_minor' => 'integer',
            'cancellation_notified_at' => 'datetime',
            'possession_on' => 'date',
            'refund_due_on' => 'date',
            'goods_sent_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'refund_net_minor' => 'integer',
            'refund_tax_minor' => 'integer',
            'refund_gross_minor' => 'integer',
            'delivery_refund_net_minor' => 'integer',
            'delivery_refund_tax_minor' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'return_by_date' => 'date',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<RmaLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RmaLine::class);
    }
}
