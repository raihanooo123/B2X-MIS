<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §14.5.2 — invoices. `shipment_id` is a deferred foreign key —
 * `shipments` (§14.6) is not yet signed off — so it stays a plain nullable
 * column here rather than a real `REFERENCES`, mirroring the
 * `order_lines.sku_cost_id` precedent (§8.3). `paid_minor` is a
 * projection sourced from `payment_allocations`/`payments` (§11.4), never
 * written directly outside that rebuild.
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
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
