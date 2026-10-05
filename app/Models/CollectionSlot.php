<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05.6 §7.1, §7A.1 — a bookable collection window at a location, in UK
 * local time. Rows are generated from the location's weekly pattern; a
 * booking only ever refers to an existing row.
 *
 * @property int $id
 * @property int $location_id
 * @property Carbon $slot_date
 * @property string $start_time
 * @property string $end_time
 * @property int $capacity
 * @property int $booked_count
 * @property string $status open | full | closed
 * @property string|null $note
 */
class CollectionSlot extends Model
{
    protected $fillable = ['location_id', 'slot_date', 'start_time', 'end_time', 'capacity', 'booked_count', 'status', 'note'];

    protected function casts(): array
    {
        return ['slot_date' => 'date', 'capacity' => 'integer', 'booked_count' => 'integer'];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<CollectionBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(CollectionBooking::class);
    }
}
