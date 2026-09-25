<?php

namespace App\Models;

use Database\Factories\CreditHoldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 05.2 §7.1 — credit_holds. An order holds credit the way it
 * allocates stock; an invoice consumes it the way a dispatch consumes
 * stock (§6). `credit_holds_order_uq` makes a hold idempotent per order.
 *
 * `held_at`/`released_at`/`invoice_id` are maintained by the credit
 * domain logic (05.2 §8.3's hold lifecycle table), never set ad hoc —
 * that write discipline belongs with the checkout/invoicing services,
 * not this model.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int $amount_minor
 * @property string $status
 * @property int|null $invoice_id
 */
class CreditHold extends Model
{
    /** @use HasFactory<CreditHoldFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'order_id',
        'amount_minor',
        'status',
        'held_at',
        'released_at',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'held_at' => 'datetime',
            'released_at' => 'datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
