<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Doc 02 §4.2 — users.
 *
 * @property string|null $password_hash
 * @property string $first_name
 * @property string $last_name
 */
class User extends Authenticatable implements FilamentUser, HasName
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
     * CLAUDE.md: Filament resources "go through the same Policies as
     * everything else — no separate authorisation path." See
     * UserPolicy::accessAdminPanel() for the actual rule (holds any
     * `role_user` row).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('accessAdminPanel', self::class);
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

    public function getFilamentName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * @param  list<string>  $codes  `roles.code` values (02 §14.1's
     *                               six-value closed list)
     */
    public function hasAnyRole(array $codes): bool
    {
        return $this->roles()->whereIn('code', $codes)->exists();
    }
}
