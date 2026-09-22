<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\CatalogueRoles;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Deny-by-default: a role not named in CatalogueRoles gets nothing on
 * Category — warehouse and accounts included, per the explicit brief
 * ("nothing on Brand/Category" for both).
 */
final class CategoryPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MERCHANDISING_VIEWERS);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasAnyRole(CatalogueRoles::MANAGERS);
    }
}
