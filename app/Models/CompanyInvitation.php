<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §17.1 — company_invitations. States are derived from the
 * timestamps (open / accepted / revoked / expired), never stored. The
 * invitation flows themselves (05.13 §9) are not built yet; this model
 * exists so the table has one.
 *
 * @property int $id
 * @property int $company_id
 * @property string $email
 * @property string $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class CompanyInvitation extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'company_id',
        'email',
        'first_name',
        'last_name',
        'role',
        'order_limit_minor',
        'requires_approval',
        'token_hash',
        'invited_by_user_id',
        'accepted_by_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'order_limit_minor' => 'integer',
            'requires_approval' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
