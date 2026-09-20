<?php

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §14.5.4 — payment_allocations. Cash application: which specific
 * invoice(s) a payment settles. Append-only by design — no
 * `UNIQUE(payment_id, invoice_id)` — a reversed-then-reapplied allocation is
 * three rows, never one row overwritten twice. `amount_minor` is signed:
 * positive for an application, negative for a refund/reallocation.
 *
 * No `public_id`: this table is never addressed by its own URL, only ever
 * read through its `payment`/`invoice` parents.
 */
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'amount_minor',
        'allocation_reference',
        'reason_code',
        'actor_user_id',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'allocated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
