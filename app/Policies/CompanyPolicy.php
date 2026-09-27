<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

final class CompanyPolicy
{
    use DeniesDeletion;

    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['admin']);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Company $company): bool
    {
        return false;
    }

    public function manageMembers(User $user, Company $company): bool
    {
        return $user->status === 'active' && ($user->hasAnyRole(['admin'])
            || (! $user->isStaff() && CompanyUser::query()->where('company_id', $company->id)
                ->where('user_id', $user->id)->where('role', 'owner')->exists()));
    }
}
