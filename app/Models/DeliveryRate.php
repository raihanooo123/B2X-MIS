<?php

namespace App\Models;

use Database\Factories\DeliveryRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 05.6 §5.2 — delivery_rates. Rebuilt 2026-09-22 to match the
 * authoritative module spec: rates are banded by carrier weight
 * (`weight_range`, an int4range in grams) and `method`, not by order
 * spend. The applicable row is resolved per zone+method by the band
 * whose `weight_range` contains the parcel's weight, within an active,
 * current-validity window — that resolution walk is domain logic
 * elsewhere, not this model. `weight_range` and `validity` are native
 * Postgres range types; left uncast, same as TaxRate/PriceList.
 */
class DeliveryRate extends Model
{
    /** @use HasFactory<DeliveryRateFactory> */
    use HasFactory;

    protected $fillable = [
        'zone_id',
        'method',
        'weight_range',
        'price_net_minor',
        'per_extra_kg_minor',
        'tax_class_id',
        'validity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_net_minor' => 'integer',
            'per_extra_kg_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    /**
     * @return BelongsTo<TaxClass, $this>
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }
}
