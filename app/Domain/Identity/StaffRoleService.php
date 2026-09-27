<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.13 §5.3: an administrator grants or revokes an existing staff member's
 * roles (02 §14.1 `role_user`), one role per call, each change audited in the
 * same transaction (02 §15.2 decision 3).
 *
 * Concurrency: every change first locks the `admin` row of `roles`, then the
 * subject's `users` row — always in that order. Serialising on the admin role
 * means the actor's own authority, the subject's role set and the count of
 * active administrators are all read after any competing change commits, so
 * two administrators revoking each other (or the last two admins) cannot both
 * succeed. Suspension, when it is built, must take the same admin-role lock
 * before it changes an administrator's status.
 */
final class StaffRoleService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function grant(User $staff, string $roleCode, User $actor): void
    {
        $this->change($staff, $roleCode, $actor, function (User $locked, Role $role, ?RoleUser $held) use ($actor, $roleCode): void {
            if ($held !== null) {
                throw ValidationException::withMessages(['role' => 'This staff member already holds that role.']);
            }

            try {
                RoleUser::create([
                    'role_id' => $role->id,
                    'user_id' => $locked->id,
                    'granted_by_user_id' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['role' => 'This staff member already holds that role.']);
            }

            $this->record($actor, $locked, AuditAction::StaffRoleGranted, after: ['role' => $roleCode]);
        });
    }

    public function revoke(User $staff, string $roleCode, User $actor): void
    {
        $this->change($staff, $roleCode, $actor, function (User $locked, Role $role, ?RoleUser $held) use ($actor, $roleCode): void {
            if ($held === null) {
                throw ValidationException::withMessages(['role' => 'This staff member does not hold that role.']);
            }
            if (RoleUser::query()->where('user_id', $locked->id)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'A staff member must keep at least one role. Suspension is a separate action.']);
            }
            if ($roleCode === 'admin' && ! $this->anotherActiveAdminExists($locked)) {
                throw ValidationException::withMessages(['role' => 'The last active administrator cannot lose the admin role.']);
            }

            RoleUser::query()->where('user_id', $locked->id)->where('role_id', $role->id)->delete();

            $this->record($actor, $locked, AuditAction::StaffRoleRevoked, before: [
                'role' => $roleCode,
                'granted_by_user_id' => $held->granted_by_user_id,
            ]);
        });
    }

    /**
     * @param  callable(User, Role, ?RoleUser): void  $apply
     */
    private function change(User $staff, string $roleCode, User $actor, callable $apply): void
    {
        if (! in_array($roleCode, StaffOnboardingService::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Choose a valid staff role.']);
        }

        DB::transaction(function () use ($staff, $roleCode, $actor, $apply): void {
            $adminRole = Role::query()->where('code', 'admin')->lockForUpdate()->first();
            $role = $roleCode === 'admin' ? $adminRole : Role::query()->where('code', $roleCode)->first();
            if ($adminRole === null || $role === null) {
                throw ValidationException::withMessages(['role' => 'Staff roles are not configured.']);
            }

            $locked = User::query()->lockForUpdate()->findOrFail($staff->id);
            $currentActor = User::query()->findOrFail($actor->id);
            if ($currentActor->id === $locked->id) {
                throw new AuthorizationException('Administrators cannot change their own roles.');
            }
            Gate::forUser($currentActor)->authorize('manageStaffRoles', $locked);

            $held = RoleUser::query()->where('user_id', $locked->id)->where('role_id', $role->id)->first();

            $apply($locked, $role, $held);
        });
    }

    private function anotherActiveAdminExists(User $staff): bool
    {
        return User::query()
            ->whereKeyNot($staff->id)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('code', 'admin'))
            ->exists();
    }

    /**
     * @param  array<string, int|string|null>  $before
     * @param  array<string, string>  $after
     */
    private function record(User $actor, User $staff, AuditAction $action, array $before = [], array $after = []): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            actorType: 'user',
            actorUserId: $actor->id,
            subjectType: 'user',
            subjectId: $staff->id,
            before: $before,
            after: $after,
        ));
    }
}
