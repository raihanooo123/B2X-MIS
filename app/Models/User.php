<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Doc 02 §4.2 — users.
 *
 * @property string|null $password_hash
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /**
     * Doc 02 has no `password` or `remember_token` column on `users` —
     * the auth password lives in `password_hash`, and remember-me tokens
     * are not part of the model.
     */
    protected $rememberTokenName = '';

    protected $fillable = [
        'public_id',
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'phone',
        'status',
        'locale',
        'default_max_discount_bp',
    ];

    protected $hidden = [
        'password_hash',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'default_max_discount_bp' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    /**
     * @return BelongsToMany<Company, $this, CompanyUser, 'pivot'>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->using(CompanyUser::class)
            ->withPivot(['role', 'order_limit_minor', 'requires_approval', 'is_default_contact']);
    }

    /**
     * Internal staff RBAC (02 §14.1) — distinct from company()/companies()'
     * B2B customer-side role. Read-only convenience relation — grant a role
     * via `RoleUser::create(['role_id' => ..., 'user_id' => ...])`, not
     * `->attach()` (see `Role::users()`'s docblock for why).
     *
     * @return BelongsToMany<Role, $this, RoleUser, 'pivot'>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->using(RoleUser::class)
            ->withPivot('granted_by_user_id', 'created_at');
    }
}
