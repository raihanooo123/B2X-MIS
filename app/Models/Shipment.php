<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §14.6 — shipments. What triggers the `dispatch` stock movement
 * (04 §7.2); generated per shipment, not per order (05.5 §5.1), so one
 * order may have several. No `shipment_number` — `public_id` is the
 * external reference, a courier tracking number the customer-facing one.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $location_id
 * @property string $fulfilment_type
 * @property string $status
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property int|null $parcel_count
 * @property int|null $total_weight_g
 * @property Carbon|null $picked_at
 * @property Carbon|null $packed_at
 * @property Carbon|null $dispatched_at
 */
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'order_id',
        'location_id',
        'fulfilment_type',
        'status',
        'carrier',
        'tracking_number',
        'parcel_count',
        'total_weight_g',
        'note',
        'picked_at',
        'packed_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'parcel_count' => 'integer',
            'total_weight_g' => 'integer',
            'picked_at' => 'datetime',
            'packed_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<ShipmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
