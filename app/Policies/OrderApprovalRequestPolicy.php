<?php
namespace App\Policies;
use App\Models\CompanyUser;
use App\Models\OrderApprovalRequest;
use App\Models\User;
final class OrderApprovalRequestPolicy
{
    public function view(User $user, OrderApprovalRequest $request): bool
    {
        return $user->status === 'active' && ($user->hasAnyRole(['accounts','admin'])
            || (! $user->isStaff() && CompanyUser::query()->where('company_id', $request->company_id)->where('user_id', $user->id)->whereIn('role', ['owner','approver'])->exists()));
    }
    public function decide(User $user, OrderApprovalRequest $request): bool
    {
        if ($request->requested_by_user_id === $user->id || $user->status !== 'active') { return false; }
        return $request->approval_kind === 'credit_exception'
            ? $user->hasAnyRole(['accounts','admin'])
            : ! $user->isStaff() && CompanyUser::query()->where('company_id', $request->company_id)->where('user_id', $user->id)->whereIn('role', ['owner','approver'])->exists();
    }
}
