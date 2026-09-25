<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 05.7 §4 — suppliers. `default_incoterm` decides whether freight is
 * apportioned into landed cost (§8.4).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $country_code
 * @property string $default_currency
 * @property string $default_incoterm
 * @property string $status
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'country_code',
        'default_currency',
        'default_incoterm',
        'lead_time_days',
        'payment_terms',
        'contact_name',
        'contact_email',
        'contact_phone',
        'address',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'address' => 'array',
        ];
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
