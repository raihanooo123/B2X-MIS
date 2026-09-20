<?php

namespace App\Models;

use Database\Factories\DeliveryZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §8.5 (amendment, signed off 2026-09-21) — delivery_zones. No
 * public_id — internal operational configuration, like price_tiers and
 * tax_classes, never exposed in a customer-facing URL.
 */
class DeliveryZone extends Model
{
    /** @use HasFactory<DeliveryZoneFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'status',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<DeliveryZonePostcode, $this>
     */
    public function postcodes(): HasMany
    {
        return $this->hasMany(DeliveryZonePostcode::class);
    }

    /**
     * @return HasMany<DeliveryRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(DeliveryRate::class, 'zone_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
