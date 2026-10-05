<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function slot(): BelongsTo
    {
        return $this->belongsTo(CollectionSlot::class, 'collection_slot_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
