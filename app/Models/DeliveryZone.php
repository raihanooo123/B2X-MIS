<?php

namespace App\Models;

use Database\Factories\DeliveryZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §8.5 (amendment, signed off 2026-09-21) — delivery_zones. No
 * public_id — internal operational configuration, like price_tiers and
 * tax_classes, never exposed in a customer-facing URL. Rating flags and
 * the per-zone carriage-paid threshold per 05.6 §4.2 (02 §20.1).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property string $country_code
 * @property bool $is_mainland
 * @property bool $is_serviceable
 * @property bool $requires_manual_quote
 * @property int|null $carriage_paid_threshold_minor
 * @property int|null $transit_days
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
        'country_code',
        'is_mainland',
        'is_serviceable',
        'requires_manual_quote',
        'carriage_paid_threshold_minor',
        'transit_days',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_mainland' => 'boolean',
            'is_serviceable' => 'boolean',
            'requires_manual_quote' => 'boolean',
            'carriage_paid_threshold_minor' => 'integer',
            'transit_days' => 'integer',
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
