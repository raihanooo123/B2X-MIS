<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §14.5.3 — credit_notes, as amended by 05.15 §9 A8. `company_id` NULL
 * is a receipt's credit note (a public order); `credit_notes_owner_chk`
 * then requires its order or receipt. Gapless numbering through
 * `number_sequences` (`credit_note_number`).
 *
 * @property int $id
 * @property string $public_id
 * @property string $credit_note_number
 * @property int|null $company_id
 * @property int|null $order_id
 * @property int|null $invoice_id
 * @property int|null $rma_id
 * @property string $reason
 * @property string $status
 * @property string $currency
 * @property int $subtotal_net_minor
 * @property int $tax_minor
 * @property int $total_gross_minor
 * @property Carbon $issued_at
 * @property-read Order|null $order
 * @property-read Invoice|null $invoice
 * @property-read Company|null $company
 */
class CreditNote extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'credit_note_number',
        'company_id',
        'order_id',
        'invoice_id',
        'rma_id',
        'reason',
        'status',
        'currency',
        'subtotal_net_minor',
        'tax_minor',
        'total_gross_minor',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_net_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_gross_minor' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
