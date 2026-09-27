<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\B2bApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §4.6 — b2b_applications. `address` is `jsonb` (GIN-indexed) rather
 * than a normalised structure — postcode/city inside the application need
 * to be queryable for duplicate detection, but an application isn't yet a
 * `companies`/`addresses` row.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $company_id
 * @property int|null $applicant_user_id
 * @property string $company_name
 * @property string|null $vat_number
 * @property string|null $registration_number
 * @property string $contact_name
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string|null $business_type
 * @property int|null $estimated_monthly_spend_minor
 * @property array<string, mixed> $address
 * @property string $status
 * @property int|null $requested_tier_id
 * @property int|null $granted_tier_id
 * @property int|null $reviewer_user_id
 * @property string|null $review_note
 * @property string|null $info_request
 * @property string|null $legal_form
 * @property string|null $rejection_category
 * @property bool|null $rejection_remediable
 * @property string|null $applicant_message
 * @property Carbon|null $reapply_after
 * @property Carbon|null $reviewed_at
 * @property Carbon $submitted_at
 */
class B2bApplication extends Model
{
    /** 05.2 §4: the statuses the review queue works on (`b2b_applications_queue_idx`). */
    public const OPEN_STATUSES = ['submitted', 'in_review', 'info_requested'];

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
        'legal_form',
        'reviewed_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_monthly_spend_minor' => 'integer',
            'address' => 'array',
            'reviewed_at' => 'datetime',
            'rejection_remediable' => 'boolean',
            'reapply_after' => 'datetime',
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
     * 02 §25.1: the terms the applicant accepted. NULL for applications
     * filed before terms were versioned.
     *
     * @return HasOne<TermsAcceptance, $this>
     */
    public function termsAcceptance(): HasOne
    {
        return $this->hasOne(TermsAcceptance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
