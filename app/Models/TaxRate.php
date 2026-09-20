<?php

namespace App\Models;

use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §6.5 — tax_rates. Dated, non-overlapping per (tax_class, country,
 * region) via `EXCLUDE USING gist` — enforced by the database, not here.
 *
 * `validity` is a native `tstzrange`; it is intentionally left as the raw
 * Postgres range string rather than cast, since parsing/constructing
 * ranges for resolution queries is domain logic for 03 — Pricing Engine.
 */
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tax_class_id',
        'country_code',
        'region',
        'rate_bp',
        'validity',
    ];

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TaxClass, $this>
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }
}
