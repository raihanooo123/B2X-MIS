<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use App\Policies\Concerns\CatalogueRoles;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Deny-by-default: a role not named in CatalogueRoles gets nothing on
 * Brand — warehouse and accounts included, per the explicit brief
 * ("nothing on Brand/Category" for both).
 */
final class BrandPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MERCHANDISING_VIEWERS);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }
}
