<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §14.5.2 — invoices. `shipment_id` references `shipments` (§14.6),
 * a foreign key added after both tables existed
 * (2026_10_08_090500_add_shipment_fk_to_invoices_table). `paid_minor` is a
 * projection sourced from `payment_allocations`/`payments` (§11.4): written
 * only by the transaction that writes an allocation
 * (PaymentAllocationService), which enforces §11.4's two invariants, or by
 * the rebuild.
 *
 * §21.2: a row with no company is a public customer's **receipt** —
 * `payment_terms` and `due_at` are NULL with it (`invoices_kind_chk`).
 * Issued only by App\Domain\Billing\InvoiceService.
 *
 * @property int $id
 * @property string $public_id
 * @property string $invoice_number
 * @property int|null $company_id
 * @property int $order_id
 * @property int|null $shipment_id
 * @property string $status
 * @property string $currency
 * @property int $subtotal_net_minor
 * @property int $discount_net_minor
 * @property int $shipping_net_minor
 * @property int $tax_minor
 * @property int $total_gross_minor
 * @property int $paid_minor
 * @property string|null $payment_terms
 * @property Carbon|null $due_at
 * @property Carbon $issued_at
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'invoice_number',
        'company_id',
        'order_id',
        'shipment_id',
        'status',
        'currency',
        'subtotal_net_minor',
        'discount_net_minor',
        'shipping_net_minor',
        'tax_minor',
        'total_gross_minor',
        'paid_minor',
        'payment_terms',
        'due_at',
        'issued_at',
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
            'paid_minor' => 'integer',
            'due_at' => 'datetime',
            'issued_at' => 'datetime',
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
     * Set only on a per-shipment invoice (05.5 §7.3).
     *
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** 02 §21.2: no company means a public customer's receipt, not a VAT invoice. */
    public function isReceipt(): bool
    {
        return $this->company_id === null;
    }

    /**
     * The lines this document bills, derived from `order_lines` (02
     * §14.5.2 — there is no `invoice_lines`). Whole-order documents only:
     * a per-shipment invoice joins through `shipment_lines` (§14.6), and
     * InvoiceService issues none yet.
     *
     * @return HasMany<OrderLine, $this>
     */
    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id', 'order_id')->orderBy('line_no');
    }

    /**
     * The PDF archived at issue (02 §21.1) — what the customer received.
     *
     * @return HasOne<Attachment, $this>
     */
    public function archivedPdf(): HasOne
    {
        return $this->hasOne(Attachment::class, 'attachable_id')
            ->where('attachable_type', 'invoice')
            ->where('mime_type', 'application/pdf')
            ->oldest('id');
    }
}
