<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Doc 02 §5.5 — skus, the central stockable/priceable/sellable entity.
 *
 * @property string $status
 * @property string $sku_code
 * @property int $product_id
 * @property int|null $unit_weight_g
 * @property bool $is_stock_tracked
 * @property string $tracking_mode
 * @property bool $allow_backorder
 * @property string $public_id
 * @property string|null $variant_label
 * @property int $moq_base_qty
 * @property int $order_increment_base_qty
 * @property int|null $max_order_base_qty
 * @property int|null $default_pack_id
 * @property string|null $barcode_ean
 * @property bool $requires_expiry
 * @property int|null $shelf_life_days
 */
class Sku extends Model
{
    /** @use HasFactory<SkuFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'product_id',
        'sku_code',
        'barcode_ean',
        'supplier_ref',
        'variant_label',
        'status',
        'tax_class_id',
        'base_unit',
        'unit_weight_g',
        'moq_base_qty',
        'order_increment_base_qty',
        'max_order_base_qty',
        'default_pack_id',
        'is_stock_tracked',
        'tracking_mode',
        'allocation_strategy',
        'requires_expiry',
        'shelf_life_days',
        'min_remaining_shelf_life_days',
        'allow_backorder',
        'is_refundable',
        'non_refundable_reason',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'unit_weight_g' => 'integer',
            'moq_base_qty' => 'integer',
            'order_increment_base_qty' => 'integer',
            'max_order_base_qty' => 'integer',
            'is_stock_tracked' => 'boolean',
            'requires_expiry' => 'boolean',
            'shelf_life_days' => 'integer',
            'min_remaining_shelf_life_days' => 'integer',
            'allow_backorder' => 'boolean',
            'is_refundable' => 'boolean',
            'position' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<TaxClass, $this>
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    /**
     * @return HasMany<Pack, $this>
     */
    public function packs(): HasMany
    {
        return $this->hasMany(Pack::class);
    }

    /**
     * @return BelongsTo<Pack, $this>
     */
    public function defaultPack(): BelongsTo
    {
        return $this->belongsTo(Pack::class, 'default_pack_id');
    }

    /**
     * Full landed-cost history, newest first — mirrors
     * sku_costs_current_idx (sku_id, valid_from DESC). "Current cost" is
     * `costs()->first()`, not a separate query.
     *
     * @return HasMany<SkuCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(SkuCost::class)->latest('valid_from');
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * The ledger for this SKU. `stock_movements` carries no database-level
     * foreign key by design (§7.4 point 3) — this relation is an ORM
     * query convenience only, not referential integrity.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<StockAllocation, $this>
     */
    public function stockAllocations(): HasMany
    {
        return $this->hasMany(StockAllocation::class);
    }

    /**
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
