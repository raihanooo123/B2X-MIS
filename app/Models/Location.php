<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §7.3 — locations. Present from day one even though launch is
 * single-warehouse (§7.3 rationale).
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'location_type',
        'is_sellable',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * The ledger at this location. `stock_movements` carries no
     * database-level foreign key by design (§7.4 point 3) — this
     * relation is an ORM query convenience only, not referential
     * integrity.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<SystemConfiguration, $this>
     */
    public function systemConfigurations(): HasMany
    {
        return $this->hasMany(SystemConfiguration::class);
    }

    /**
     * @return HasMany<StockAllocation, $this>
     */
    public function stockAllocations(): HasMany
    {
        return $this->hasMany(StockAllocation::class);
    }

    /**
     * @return HasMany<Bin, $this>
     */
    public function bins(): HasMany
    {
        return $this->hasMany(Bin::class);
    }
}
