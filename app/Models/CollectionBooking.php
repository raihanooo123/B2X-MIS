<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 05.6 §7.1, §7A.13 — an order's one collection booking. `company_id` is
 * NULL for a public or guest order (A4); the order names the customer.
 * `payment_due_by` is set only for pay at collection: the slot's end plus
 * the grace period, snapshotted at booking.
 *
 * @property int $id
 * @property int $collection_slot_id
 * @property int $order_id
 * @property int|null $company_id
 * @property string $status booked | collected | no_show | cancelled
 * @property string|null $collector_name
 * @property string|null $vehicle_registration
 * @property Carbon|null $collected_at
 * @property Carbon|null $payment_due_by
 * @property int|null $handed_over_by_user_id
 * @property Carbon|null $no_show_at
 * @property int $reschedule_count
 */
class CollectionBooking extends Model
{
    protected $fillable = [
        'collection_slot_id', 'order_id', 'company_id', 'status', 'collector_name',
        'vehicle_registration', 'collected_at', 'payment_due_by',
        'handed_over_by_user_id', 'no_show_at', 'reschedule_count',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime', 'payment_due_by' => 'datetime',
            'no_show_at' => 'datetime', 'reschedule_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CollectionSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(CollectionSlot::class, 'collection_slot_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
