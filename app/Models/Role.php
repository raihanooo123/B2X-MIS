<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Doc 02 §14.1 — roles. Internal staff RBAC (FilamentPHP admin gating, Policy
 * checks) — distinct from CompanyUser::$role, the B2B customer-side role.
 * No public_id: internal operational configuration, like PriceTier and
 * TaxClass, never addressed by its own URL.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'default_max_discount_bp',
    ];

    protected function casts(): array
    {
        return [
            'default_max_discount_bp' => 'integer',
        ];
    }

    /**
     * Read-only convenience relation. **Do not grant a role via
     * `->attach()`** — Eloquent's `BelongsToMany::attach()` with a custom
     * pivot class ignores `RoleUser::UPDATED_AT = null` and always tries to
     * write both timestamp columns, which fails against `role_user`'s real
     * schema (`created_at` only). Verified live: `RoleUser::create([...])`
     * respects the null override correctly; `$user->roles()->attach($id)`
     * does not. Grant a role with `RoleUser::create(['role_id' => ...,
     * 'user_id' => ...])` instead.
     *
     * @return BelongsToMany<User, $this, RoleUser, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->using(RoleUser::class)
            ->withPivot('granted_by_user_id', 'created_at');
    }
}
