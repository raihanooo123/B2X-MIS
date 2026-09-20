<?php

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Doc 02 §7.4 — stock_movements, the append-only source of truth
 * (CLAUDE.md non-negotiable invariant 5). No UPDATE, no DELETE, ever —
 * including administrative correction; a mistake is corrected by a new
 * compensating row with a reason code. `update()`/`delete()` are
 * overridden below to make that structural, not just documented.
 *
 * No foreign keys on this table, by deliberate design (§7.4 point 3) —
 * do not add belongsTo() relations here without revisiting that
 * trade-off first; resolve sku_id/location_id/batch_id via explicit
 * lookups instead.
 *
 * Deliberately no `updated_at` — only `created_at` (record-write time)
 * and `occurred_at` (business event time) exist, matching a row that is
 * never modified after insert.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'occurred_at',
        'sku_id',
        'location_id',
        'batch_id',
        'serial_id',
        'bin_id',
        'movement_type',
        'base_qty',
        'balance_after',
        'reference_type',
        'reference_id',
        'unit_cost_e4',
        'reason_code',
        'note',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'base_qty' => 'integer',
            'balance_after' => 'integer',
            'reference_id' => 'integer',
            'unit_cost_e4' => 'integer',
            'actor_user_id' => 'integer',
        ];
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('stock_movements is append-only (Doc 02 §7.2 rule 1, CLAUDE.md invariant 5) — insert a compensating movement instead of updating one.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('stock_movements is append-only (Doc 02 §7.2 rule 1, CLAUDE.md invariant 5) — insert a compensating movement instead of deleting one.');
    }
}
