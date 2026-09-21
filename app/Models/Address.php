<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Doc 02 §4.5 — addresses. An order never foreign-keys here: orders hold an
 * immutable snapshot in `order_addresses` (§8.4) instead, so editing or
 * deleting an address never retrospectively changes a historical order.
 */
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'label',
        'contact_name',
        'phone',
        'line1',
        'line2',
        'city',
        'county',
        'postcode',
        'country_code',
        'address_type',
        'is_default',
        'delivery_zone_id',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }
}
