<?php

namespace App\Models;

use Database\Factories\CompanyUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Doc 02 §4.4 — company_users. Trade-account role membership: the only
 * role construct this domain model defines (see the migration's note on
 * why `roles`/`role_user`/`permissions` are not scaffolded).
 */
class CompanyUser extends Pivot
{
    /** @use HasFactory<CompanyUserFactory> */
    use HasFactory;

    protected $table = 'company_users';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'order_limit_minor',
        'requires_approval',
        'is_default_contact',
    ];

    protected function casts(): array
    {
        return [
            'order_limit_minor' => 'integer',
            'requires_approval' => 'boolean',
            'is_default_contact' => 'boolean',
        ];
    }
}
