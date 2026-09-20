<?php

namespace App\Models;

use Database\Factories\BinFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §7.3 — bins. Advisory only, never locked during allocation
 * (§7.4): `suggested_bin_id` on `stock_allocations` is a hint on the
 * picking slip, not part of the allocation key — allocation happens at
 * (sku_id, location_id, batch_id) granularity only.
 */
class Bin extends Model
{
    /** @use HasFactory<BinFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'location_id',
        'code',
        'walk_sequence',
    ];

    protected function casts(): array
    {
        return [
            'walk_sequence' => 'integer',
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
     * @return HasMany<StockAllocation, $this>
     */
    public function suggestedAllocations(): HasMany
    {
        return $this->hasMany(StockAllocation::class, 'suggested_bin_id');
    }
}
