<?php

namespace App\Models;

use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Doc 02 §23.2 — goods_receipt_lines. One confirmed receipt entry, and the
 * `reference_id` of its `goods_in` movement (§23.3). Idempotent on
 * `(goods_receipt_id, purchase_order_line_id, client_token)`.
 *
 * Append-only: a line has moved stock, so it is corrected by an
 * `adjustment` movement, never edited. `update()`/`delete()` throw, as on
 * StockMovement. `sku_cost_id IS NULL` means "received without cost"
 * (05.5 §4.5). Serials are not held here: they are the `stock_serials`
 * whose `received_movement_id` is this line's movement.
 *
 * @property int $id
 * @property int $goods_receipt_id
 * @property int|null $purchase_order_line_id
 * @property string $client_token
 * @property int $sku_id
 * @property int $pack_id
 * @property int $pack_qty
 * @property int $pack_base_units
 * @property int $base_qty
 * @property int|null $batch_id
 * @property int|null $bin_id
 * @property int|null $sku_cost_id
 * @property int|null $received_by_user_id
 * @property Carbon $received_at
 */
class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_line_id',
        'client_token',
        'sku_id',
        'pack_id',
        'pack_qty',
        'pack_base_units',
        'base_qty',
        'batch_id',
        'bin_id',
        'sku_cost_id',
        'received_by_user_id',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'pack_qty' => 'integer',
            'pack_base_units' => 'integer',
            'base_qty' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('goods_receipt_lines is append-only (02 §23.2) — correct a receipt with an adjustment movement.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('goods_receipt_lines is append-only (02 §23.2) — correct a receipt with an adjustment movement.');
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Pack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Bin, $this>
     */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(Bin::class);
    }

    /**
     * @return BelongsTo<SkuCost, $this>
     */
    public function skuCost(): BelongsTo
    {
        return $this->belongsTo(SkuCost::class);
    }
}
