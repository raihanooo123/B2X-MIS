<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Doc 02 §4.3 — companies. The trade account: price tier, payment terms, credit.
 *
 * `credit_used_minor`, `credit_held_minor` and `account_balance_minor` are
 * projections maintained transactionally elsewhere (05.2 §6-8) — never set
 * them directly outside that domain logic.
 *
 * @property int $id
 * @property string $public_id
 * @property string $account_code
 * @property string $name
 * @property string $status
 * @property string $payment_terms
 * @property string $price_display_mode
 * @property int $credit_limit_minor
 * @property int $credit_used_minor
 * @property int $credit_held_minor
 * @property int $account_balance_minor
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'account_code',
        'name',
        'trading_name',
        'vat_number',
        'registration_number',
        'status',
        'price_tier_id',
        'payment_terms',
        'tax_exempt',
        'price_display_mode',
        'assigned_rep_user_id',
        'xero_contact_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit_minor' => 'integer',
            'credit_used_minor' => 'integer',
            'credit_held_minor' => 'integer',
            'account_balance_minor' => 'integer',
            'tax_exempt' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, CompanyUser, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->using(CompanyUser::class)
            ->withPivot(['role', 'order_limit_minor', 'requires_approval', 'is_default_contact']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_rep_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<PriceTier, $this>
     */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}
