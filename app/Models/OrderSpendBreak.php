<?php

namespace App\Models;

use Database\Factories\OrderSpendBreakFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §6.7 — order_spend_breaks. Order-wide spend thresholds applied
 * as a second pass after item-level pricing resolves. Semantics: exactly
 * one break applies, never stacked; precedence company > tier > global,
 * then priority DESC, then id DESC — resolution and apportionment are
 * domain logic for 03 — Pricing Engine, not this model.
 *
 * @property string $discount_type
 * @property int|null $discount_rate_bp
 * @property int|null $discount_amount_minor
 * @property int|null $max_discount_minor
 * @property bool $applies_to_contract_lines
 * @property string $code
 * @property string $name
 */
class OrderSpendBreak extends Model
{
    /** @use HasFactory<OrderSpendBreakFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'scope',
        'price_tier_id',
        'company_id',
        'min_subtotal_minor',
        'discount_type',
        'discount_rate_bp',
        'discount_amount_minor',
        'max_discount_minor',
        'currency',
        'applies_to_contract_lines',
        'priority',
        'validity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'min_subtotal_minor' => 'integer',
            'discount_rate_bp' => 'integer',
            'discount_amount_minor' => 'integer',
            'max_discount_minor' => 'integer',
            'applies_to_contract_lines' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceTier, $this>
     */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
