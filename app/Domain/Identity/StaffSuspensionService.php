<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * 05.13 §4.2, §13.3: an administrator suspends or reinstates another staff
 * member. Suspension ends every session and discards any password-reset
 * token in the same transaction as the status change and its audit row.
 *
 * Locks follow StaffRoleService's order — the `admin` row of `roles`, then
 * the subject's `users` row — so suspending an administrator and revoking
 * one's admin role serialise against each other, and the last active
 * administrator can be neither suspended nor demoted by concurrent changes.
 */
final class StaffSuspensionService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function suspend(User $staff, User $actor): void
    {
        $this->change($staff, $actor, 'suspendStaff', function (User $locked) use ($actor): void {
            if ($locked->hasAnyRole(['admin']) && ! $this->anotherActiveAdminExists($locked)) {
                throw ValidationException::withMessages(['status' => 'The last active administrator cannot be suspended.']);
            }

            $locked->forceFill(['status' => 'suspended'])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $this->tokens()->delete($locked);

            $this->record($actor, $locked, AuditAction::StaffSuspended, 'active', 'suspended');
        });
    }

    public function reinstate(User $staff, User $actor): void
    {
        $this->change($staff, $actor, 'reinstateStaff', function (User $locked) use ($actor): void {
            $locked->forceFill(['status' => 'active'])->save();

            $this->record($actor, $locked, AuditAction::StaffReinstated, 'suspended', 'active');
        });
    }

    /**
     * @param  callable(User): void  $apply
     */
    private function change(User $staff, User $actor, string $ability, callable $apply): void
    {
        DB::transaction(function () use ($staff, $actor, $ability, $apply): void {
            if (Role::query()->where('code', 'admin')->lockForUpdate()->first() === null) {
                throw ValidationException::withMessages(['status' => 'Staff roles are not configured.']);
            }

            $locked = User::query()->lockForUpdate()->findOrFail($staff->id);
            $currentActor = User::query()->findOrFail($actor->id);
            if ($currentActor->id === $locked->id) {
                throw new AuthorizationException('Administrators cannot suspend or reinstate themselves.');
            }
            Gate::forUser($currentActor)->authorize($ability, $locked);

            $apply($locked);
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

    private function record(User $actor, User $staff, AuditAction $action, string $from, string $to): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            actorType: 'user',
            actorUserId: $actor->id,
            subjectType: 'user',
            subjectId: $staff->id,
            before: ['status' => $from],
            after: ['status' => $to],
        ));
    }

    private function tokens(): TokenRepositoryInterface
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker();

        return $broker->getRepository();
    }
}
