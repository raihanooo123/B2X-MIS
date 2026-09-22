<?php

namespace App\Policies;

use App\Models\Pack;
use App\Models\User;
use App\Policies\Concerns\CatalogueRoles;
use App\Policies\Concerns\DeniesDeletion;

/**
 * No standalone PackResource — Pack is only reached through
 * SkuResource's PacksRelationManager — but Filament authorises a
 * relation manager's rows through the related model's own Policy
 * exactly like a top-level resource, so this still has to exist.
 * Deny-by-default: a role not named in CatalogueRoles::OPERATIONAL_VIEWERS
 * gets nothing here.
 */
final class PackPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::OPERATIONAL_VIEWERS);
    }

    public function view(User $user, Pack $pack): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }

    public function update(User $user, Pack $pack): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }
}
