<?php

namespace App\Models;

use Database\Factories\SkuCostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §6.6 — sku_costs. Landed cost for real margin.
 *
 * `landed_cost_e4` is a STORED generated column (fob_e4 + freight_e4 +
 * duty_e4 + other_e4) — never write to it, Postgres rejects it.
 *
 * "Current cost for this SKU" is `sku_costs_current_idx`'s job
 * (sku_id, valid_from DESC) — resolving and snapshotting it onto
 * `order_lines.unit_cost_e4` at order placement is domain logic for
 * 03 — Pricing Engine, not this model.
 *
 * @property int $id
 * @property int|null $landed_cost_e4
 */
class SkuCost extends Model
{
    /** @use HasFactory<SkuCostFactory> */
    use HasFactory;

    public $timestamps = false;

    /** `landed_cost_e4` is a STORED generated column and is deliberately not listed — Postgres rejects writes to it. */
    protected $fillable = [
        'sku_id',
        'source',
        'purchase_order_id',
        'container_id',
        'currency',
        'fx_rate_e4',
        'fob_e4',
        'freight_e4',
        'duty_e4',
        'other_e4',
        'is_provisional',
        'valid_from',
    ];

    protected function casts(): array
    {
        return [
            'fx_rate_e4' => 'integer',
            'fob_e4' => 'integer',
            'freight_e4' => 'integer',
            'duty_e4' => 'integer',
            'other_e4' => 'integer',
            'landed_cost_e4' => 'integer',
            'is_provisional' => 'boolean',
            'valid_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
