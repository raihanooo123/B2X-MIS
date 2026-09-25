<?php

namespace App\Models;

use Database\Factories\ContainerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doc 05.7 §6 — containers. Carries goods from several POs; its costs are
 * apportioned across them (§8). No `public_id` in the DDL — `container_ref`
 * is the scanned and displayed reference (05.5 §4.2).
 *
 * @property int $id
 * @property string $container_ref
 * @property int $location_id
 * @property string $status
 * @property string $apportionment_basis
 * @property Carbon|null $arrived_at
 * @property Carbon|null $costs_finalised_at
 */
class Container extends Model
{
    /** @use HasFactory<ContainerFactory> */
    use HasFactory;

    protected $fillable = [
        'container_ref',
        'bill_of_lading',
        'vessel_name',
        'container_type',
        'origin_port',
        'destination_port',
        'location_id',
        'status',
        'etd_date',
        'eta_date',
        'arrived_at',
        'cleared_at',
        'apportionment_basis',
        'costs_finalised_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'etd_date' => 'date',
            'eta_date' => 'date',
            'arrived_at' => 'datetime',
            'cleared_at' => 'datetime',
            'costs_finalised_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
