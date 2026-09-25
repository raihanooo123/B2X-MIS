<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §23.1 — goods_receipts. One receiving session against a purchase
 * order, a container, or nothing (manual). Opened, added to, and closed
 * only by App\Domain\Warehouse\GoodsInService.
 *
 * @property int $id
 * @property string $public_id
 * @property string $source
 * @property int|null $purchase_order_id
 * @property int|null $container_id
 * @property int $location_id
 * @property string $status
 * @property int|null $opened_by_user_id
 * @property int|null $closed_by_user_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $note
 */
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'source',
        'purchase_order_id',
        'container_id',
        'location_id',
        'status',
        'opened_by_user_id',
        'closed_by_user_id',
        'opened_at',
        'closed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
