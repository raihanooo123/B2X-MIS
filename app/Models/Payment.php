<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §14.5.1 — payments. Refunds are separate rows (`type = 'refund'`,
 * `refunded_payment_id`), never a mutated amount or a negative value on the
 * original row. Card data never stored beyond `card_brand`/`card_last4`
 * (07-nfr.md §6.4, SAQ-A scope).
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'order_id',
        'company_id',
        'type',
        'gateway',
        'gateway_reference',
        'status',
        'amount_minor',
        'currency',
        'card_brand',
        'card_last4',
        'refunded_payment_id',
        'failure_reason',
        'authorized_at',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function refundedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refunded_payment_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
