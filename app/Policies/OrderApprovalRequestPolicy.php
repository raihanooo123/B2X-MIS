<?php

namespace App\Policies;

use App\Domain\Credit\ApprovalKind;
use App\Models\CompanyUser;
use App\Models\OrderApprovalRequest;
use App\Models\User;

/**
 * 05.2 §18.1, §18.3. A company's owners and approvers see its requests
 * and decide buyer-limit ones — never their own, and never a credit
 * shortfall. Accounts/admin see every request and decide only credit
 * shortfalls: they cannot approve on behalf of a company approver.
 * Viewers, buyers and reps have no route in.
 */
final class OrderApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === 'active';
    }

    public function view(User $user, OrderApprovalRequest $request): bool
    {
        return $this->isAccounts($user) || $this->isCompanyApprover($user, $request->company_id);
    }

    public function decide(User $user, OrderApprovalRequest $request): bool
    {
        if ($user->id === $request->requested_by_user_id) {
            return false;
        }

        return $request->approval_kind === ApprovalKind::CreditException->value
            ? $this->isAccounts($user)
            : $this->isCompanyApprover($user, $request->company_id);
    }

    private function isAccounts(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['accounts', 'admin']);
    }

    private function isCompanyApprover(User $user, int $companyId): bool
    {
        return $user->status === 'active' && ! $user->isStaff()
            && CompanyUser::query()->where('company_id', $companyId)->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'approver'])->exists();
    }
}
