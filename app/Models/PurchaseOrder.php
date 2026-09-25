<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doc 05.7 §5 — purchase_orders. `goods_total_minor` is in the PO
 * currency, `goods_total_base_minor` in GBP; both stored so the FX rate
 * used is never ambiguous. `po_number` is gapless from `number_sequences`
 * (CLAUDE.md invariant 8).
 *
 * @property int $id
 * @property string $public_id
 * @property string $po_number
 * @property int $supplier_id
 * @property int|null $container_id
 * @property int $location_id
 * @property string $status
 * @property string $incoterm
 * @property string $currency
 * @property int|null $fx_rate_e4
 * @property int $goods_total_minor
 * @property int $goods_total_base_minor
 * @property Carbon|null $expected_at
 * @property Carbon|null $received_at
 */
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, HasPublicId;

    /** The statuses a PO can be received against (05.7 §5.1's open set). */
    public const RECEIVABLE_STATUSES = ['confirmed', 'in_production', 'shipped', 'part_received'];

    protected $fillable = [
        'public_id',
        'po_number',
        'supplier_id',
        'container_id',
        'location_id',
        'status',
        'incoterm',
        'currency',
        'fx_rate_e4',
        'goods_total_minor',
        'goods_total_base_minor',
        'supplier_reference',
        'ordered_at',
        'expected_at',
        'received_at',
        'raised_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'fx_rate_e4' => 'integer',
            'goods_total_minor' => 'integer',
            'goods_total_base_minor' => 'integer',
            'ordered_at' => 'datetime',
            'expected_at' => 'date',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}
