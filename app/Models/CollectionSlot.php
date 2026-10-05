<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionSlot extends Model
{
    protected $fillable = ['location_id', 'slot_date', 'start_time', 'end_time', 'capacity', 'booked_count', 'status', 'note'];

    protected function casts(): array
    {
        return ['slot_date' => 'date', 'capacity' => 'integer', 'booked_count' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
