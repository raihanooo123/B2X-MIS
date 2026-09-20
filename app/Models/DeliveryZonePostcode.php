<?php

namespace App\Models;

use Database\Factories\DeliveryZonePostcodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §8.5 (amendment, signed off 2026-09-21) — delivery_zone_postcodes.
 * Maps a UK postcode area + district range to a zone.
 * delivery_zone_postcodes_uq prevents an identical range being defined
 * twice; it does not itself prevent overlapping (not identical) ranges
 * across different zones — a data-quality concern, not a structural
 * invariant, so it is not enforced by the database.
 */
class DeliveryZonePostcode extends Model
{
    /** @use HasFactory<DeliveryZonePostcodeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'delivery_zone_id',
        'area',
        'district_from',
        'district_to',
    ];

    protected function casts(): array
    {
        return [
            'district_from' => 'integer',
            'district_to' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }
}
