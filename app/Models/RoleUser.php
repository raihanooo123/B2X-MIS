<?php

namespace App\Models;

use Database\Factories\RoleUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Doc 02 §14.1 — role_user. Composite PK (user_id, role_id) serves "roles
 * held by this user"; role_user_role_idx (DB-level) is the reverse
 * direction for "who holds this role". created_at only, no updated_at —
 * a role grant is a fact, not an editable row.
 */
class RoleUser extends Pivot
{
    /** @use HasFactory<RoleUserFactory> */
    use HasFactory;

    protected $table = 'role_user';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'role_id',
        'user_id',
        'granted_by_user_id',
    ];
}
