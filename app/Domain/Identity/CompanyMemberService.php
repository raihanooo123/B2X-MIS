<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** 05.13 §9 ⚑5: company before users, shared with CustomerSuspensionService. */
final class CompanyMemberService
{
    public function update(Company $company, User $member, User $actor, CompanyMemberSettings $settings): void
    {
        $this->change($company, $member, $actor, $settings);
    }

    public function remove(Company $company, User $member, User $actor): void
    {
        $this->change($company, $member, $actor, null);
    }

    private function change(Company $company, User $member, User $actor, ?CompanyMemberSettings $settings): void
    {
        DB::transaction(function () use ($company, $member, $actor, $settings): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $users = User::query()->whereIn('id', [$actor->id, $member->id])->orderBy('id')->lockForUpdate()->get();
            $actor = $users->firstWhere('id', $actor->id);
            abort_unless($actor instanceof User, 403);
            Gate::forUser($actor)->authorize('manageMembers', $company);
            $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $member->id)->firstOrFail();
            $before = self::settings($membership);
            $after = $settings?->attributes() ?? [];
            if ($before === $after) {
                return;
            }

            $query = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $member->id);
            if ($settings === null) {
                $query->delete();
            } else {
                $query->update($after);
            }

            if (in_array($company->status, CompanyMemberships::ACTIVE_STATUSES, true)
                && ! DB::table('company_users')->join('users', 'users.id', '=', 'company_users.user_id')
                    ->where('company_users.company_id', $company->id)->where('company_users.role', 'owner')
                    ->where('users.status', 'active')->whereNull('users.deleted_at')->exists()) {
                throw ValidationException::withMessages(['role' => 'Keep at least one active owner. Make another active member an owner first.']);
            }

            (new AuditLogger)->record(new AuditEntry(
                action: $settings === null ? AuditAction::CompanyMemberRemoved : AuditAction::CompanyMemberChanged,
                actorType: 'user', actorUserId: $actor->id, companyId: $company->id,
                subjectType: 'user', subjectId: $member->id, before: $before, after: $after,
            ));
        });
    }

    /** @return array{role: string, order_limit_minor: ?int, requires_approval: bool} */
    public static function settings(CompanyUser $membership): array
    {
        return ['role' => $membership->role, 'order_limit_minor' => $membership->order_limit_minor, 'requires_approval' => $membership->requires_approval];
    }
}
