<?php

namespace App\Models;

use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §6.3 — price_lists. The scope/container in the pricing
 * resolution chain (§6.2): contract > customer-specific > promotion >
 * tier > base, first match wins. Overlapping active windows per audience
 * are unrepresentable via three scope-partitioned EXCLUDE constraints —
 * enforced by the database, not here.
 *
 * `validity` is a native `tstzrange`; left uncast like TaxRate::$validity
 * — parsing/constructing ranges for resolution is domain logic for
 * 03 — Pricing Engine.
 */
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'scope',
        'price_tier_id',
        'company_id',
        'promotion_id',
        'currency',
        'has_contract',
        'priority',
        'validity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'has_contract' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceTier, $this>
     */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<PriceListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}
