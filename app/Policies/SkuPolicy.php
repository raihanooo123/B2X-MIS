<?php

namespace App\Policies;

use App\Models\Sku;
use App\Models\User;
use App\Policies\Concerns\CatalogueRoles;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Deny-by-default: a role not named in CatalogueRoles::OPERATIONAL_VIEWERS
 * gets nothing here.
 */
final class SkuPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::OPERATIONAL_VIEWERS);
    }

    public function view(User $user, Sku $sku): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }

    public function update(User $user, Sku $sku): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }
}
