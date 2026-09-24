<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §8.2 — orders.
 *
 * `quote_id` has no FK in the doc itself — not a gap; quotes is Phase 2.
 *
 * @property int $id
 * @property string $public_id
 * @property string $order_number
 * @property int|null $company_id
 * @property int|null $user_id
 * @property string $status
 * @property string $payment_status
 * @property string|null $customer_reference
 * @property int $subtotal_net_minor
 * @property int $shipping_net_minor
 * @property int $tax_minor
 * @property int $total_gross_minor
 * @property int $spend_break_discount_minor
 * @property Carbon|null $placed_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'order_number',
        'company_id',
        'user_id',
        'placed_by_user_id',
        'sales_rep_user_id',
        'quote_id',
        'channel',
        'status',
        'payment_status',
        'fulfilment_type',
        'currency',
        'subtotal_net_minor',
        'discount_net_minor',
        'shipping_net_minor',
        'tax_minor',
        'total_gross_minor',
        'total_cost_minor',
        'spend_break_id',
        'spend_break_discount_minor',
        'account_credit_applied_minor',
        'price_tier_snapshot',
        'customer_reference',
        'delivery_zone_id',
        'required_by_date',
        'placed_at',
        'confirmed_at',
        'dispatched_at',
        'cancelled_at',
        'cancellation_fee_minor',
        'xero_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_net_minor' => 'integer',
            'discount_net_minor' => 'integer',
            'shipping_net_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_gross_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'spend_break_discount_minor' => 'integer',
            'account_credit_applied_minor' => 'integer',
            'cancellation_fee_minor' => 'integer',
            'required_by_date' => 'date',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_user_id');
    }

    /**
     * @return BelongsTo<OrderSpendBreak, $this>
     */
    public function spendBreak(): BelongsTo
    {
        return $this->belongsTo(OrderSpendBreak::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * @return HasMany<OrderAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }
}
