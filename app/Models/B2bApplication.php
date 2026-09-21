<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\B2bApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §4.6 — b2b_applications. `address` is `jsonb` (GIN-indexed) rather
 * than a normalised structure — postcode/city inside the application need
 * to be queryable for duplicate detection, but an application isn't yet a
 * `companies`/`addresses` row.
 */
class B2bApplication extends Model
{
    /** @use HasFactory<B2bApplicationFactory> */
    use HasFactory, HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'company_id',
        'applicant_user_id',
        'company_name',
        'vat_number',
        'registration_number',
        'contact_name',
        'contact_email',
        'contact_phone',
        'business_type',
        'estimated_monthly_spend_minor',
        'address',
        'status',
        'requested_tier_id',
        'granted_tier_id',
        'reviewer_user_id',
        'review_note',
        'info_request',
        'reviewed_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_monthly_spend_minor' => 'integer',
            'address' => 'array',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
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
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /**
     * @return BelongsTo<PriceTier, $this>
     */
    public function requestedTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'requested_tier_id');
    }

    /**
     * @return BelongsTo<PriceTier, $this>
     */
    public function grantedTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'granted_tier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
