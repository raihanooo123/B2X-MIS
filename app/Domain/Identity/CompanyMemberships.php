<?php

namespace App\Domain\Identity;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The companies a user can act for (05.13 §6.3): memberships of companies
 * that are `approved`, or `suspended` — a suspended company's users can
 * still see invoices and pay by card (05.2 §9). An `applied`, `rejected`
 * or `closed` company confers nothing.
 */
final class CompanyMemberships
{
    public const ACTIVE_STATUSES = ['approved', 'suspended'];

    /**
     * @return Collection<int, Company> by name
     */
    public static function for(User $user): Collection
    {
        return Company::query()
            ->join('company_users', 'company_users.company_id', '=', 'companies.id')
            ->where('company_users.user_id', $user->id)
            ->whereIn('companies.status', self::ACTIVE_STATUSES)
            ->orderBy('companies.name')
            ->orderBy('companies.id')
            ->get(['companies.*']);
    }

    /**
     * @return list<int>
     */
    public static function ids(User $user): array
    {
        return array_values(array_map('intval', self::for($user)->pluck('id')->all()));
    }
}
