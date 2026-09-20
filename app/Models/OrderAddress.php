<?php

namespace App\Models;

use Database\Factories\OrderAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §8.4 — order_addresses. Immutable by convention: no updated_at,
 * because it is never updated. Never write to `addresses` from here or
 * vice versa — an order never foreign-keys to `addresses` (§4.5).
 */
class OrderAddress extends Model
{
    /** @use HasFactory<OrderAddressFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'address_type',
        'contact_name',
        'phone',
        'company_name',
        'line1',
        'line2',
        'city',
        'county',
        'postcode',
        'country_code',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
