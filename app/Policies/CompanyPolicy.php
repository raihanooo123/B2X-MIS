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

    /**
     * 05.2 §18.3: credit summary, invoice ageing and balance history —
     * the company's owners and approvers, and accounts/admin. Buyers and
     * viewers see only the credit checkout needs.
     */
    public function viewCredit(User $user, Company $company): bool
    {
        return $user->status === 'active' && ($user->hasAnyRole(['accounts', 'admin'])
            || (! $user->isStaff() && CompanyUser::query()->where('company_id', $company->id)
                ->where('user_id', $user->id)->whereIn('role', ['owner', 'approver'])->exists()));
    }

    /**
     * 05.17 §2: company order history and order detail — every member of
     * the company, whatever their role. Staff use the admin panel.
     */
    public function viewTradeOrders(User $user, Company $company): bool
    {
        return $user->status === 'active' && ! $user->isStaff()
            && CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists();
    }

    /**
     * 05.17 §2: invoices, credit notes, statements and their PDFs — the
     * same people as the credit pages (owners and approvers, and
     * accounts/admin). Buyers and viewers see order totals only.
     */
    public function viewFinancialDocuments(User $user, Company $company): bool
    {
        return $this->viewCredit($user, $company);
    }

    /** 05.2 §18.3: the approval queue — the company's own owners and approvers. */
    public function viewApprovals(User $user, Company $company): bool
    {
        return $user->status === 'active' && ! $user->isStaff()
            && CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'approver'])->exists();
    }

    /** 05.2 §18.3: limit, terms, suspension, funding decisions and payouts — accounts/admin. */
    public function manageCredit(User $user, Company $company): bool
    {
        return $this->manageAnyCredit($user);
    }

    /** The staff credit-control screens: the credit-exception queue and every company's credit page. */
    public function manageAnyCredit(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['accounts', 'admin']);
    }

    public function manageMembers(User $user, Company $company): bool
    {
        return $user->status === 'active' && ($user->hasAnyRole(['admin'])
            || (! $user->isStaff() && CompanyUser::query()->where('company_id', $company->id)
                ->where('user_id', $user->id)->where('role', 'owner')->exists()));
    }
}
