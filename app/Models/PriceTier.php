<?php

namespace App\Models;

use Database\Factories\PriceTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §6.3 — price_tiers. `is_default` is enforced system-wide unique
 * by `price_tiers_default_uq` — never set it directly on more than one
 * row; that's a database-level invariant, not application logic to
 * duplicate here.
 */
class PriceTier extends Model
{
    /** @use HasFactory<PriceTierFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'position',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return HasMany<PriceList, $this>
     */
    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    /**
     * @return HasMany<OrderSpendBreak, $this>
     */
    public function orderSpendBreaks(): HasMany
    {
        return $this->hasMany(OrderSpendBreak::class);
    }
}
