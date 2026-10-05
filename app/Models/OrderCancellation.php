<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05.10 §2.4 — one cancellation of some or all of an order before
 * dispatch: who asked and when (CCR reg. 32 for a consumer), what it took
 * off, and the money it moved. Immutable (`order_cancellations_immutable`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property string $kind partial | whole
 * @property string $initiated_by customer | staff | system
 * @property int|null $actor_user_id
 * @property Carbon $customer_notified_at
 * @property string|null $reason_code
 * @property string|null $reason_detail
 * @property int $cancelled_net_minor
 * @property int $cancelled_tax_minor
 * @property int $cancelled_gross_minor
 * @property int $delivery_refund_net_minor
 * @property int $delivery_refund_tax_minor
 * @property int $credit_hold_reduction_minor
 * @property int|null $credit_note_id
 * @property int|null $refund_payment_id
 * @property string|null $client_token
 * @property Carbon $created_at
 */
class OrderCancellation extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'kind', 'initiated_by', 'actor_user_id', 'customer_notified_at', 'reason_code', 'reason_detail',
        'cancelled_net_minor', 'cancelled_tax_minor', 'cancelled_gross_minor', 'delivery_refund_net_minor',
        'delivery_refund_tax_minor', 'credit_hold_reduction_minor', 'credit_note_id', 'refund_payment_id', 'client_token',
    ];

    protected function casts(): array
    {
        return [
            'customer_notified_at' => 'datetime',
            'created_at' => 'datetime',
            'cancelled_net_minor' => 'integer',
            'cancelled_tax_minor' => 'integer',
            'cancelled_gross_minor' => 'integer',
            'delivery_refund_net_minor' => 'integer',
            'delivery_refund_tax_minor' => 'integer',
            'credit_hold_reduction_minor' => 'integer',
        ];
    }

    /** @return HasMany<OrderCancellationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderCancellationLine::class);
    }
}
