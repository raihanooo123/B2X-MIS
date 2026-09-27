<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notices\TwoFactorChanged;
use App\Domain\Notifications\Notifications;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.13 §12.3: an administrator resets another staff member's 2FA after a
 * lost device. The secret is cleared, 2FA disabled, the recovery codes
 * deleted (02 §17.2) and every session ended, so the next sign-in goes
 * straight to enrolment (RequireStaffTwoFactor). The change, the session
 * removal and the audit row share one transaction; the §15 security notice
 * is queued only after it commits.
 *
 * Locks follow StaffRoleService's order — the `admin` row of `roles`, then
 * the subject's `users` row — so the actor's authority and the subject's
 * status are read after any competing role change or suspension commits.
 */
final class StaffTwoFactorResetService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    public function reset(User $staff, User $actor): void
    {
        DB::transaction(function () use ($staff, $actor): void {
            if (Role::query()->where('code', 'admin')->lockForUpdate()->first() === null) {
                throw ValidationException::withMessages(['two_factor' => 'Staff roles are not configured.']);
            }

            $locked = User::query()->lockForUpdate()->findOrFail($staff->id);
            $currentActor = User::query()->findOrFail($actor->id);
            if ($currentActor->id === $locked->id) {
                throw new AuthorizationException('Administrators cannot reset their own two-factor authentication.');
            }
            Gate::forUser($currentActor)->authorize('resetStaffTwoFactor', $locked);

            $locked->forceFill(['two_factor_secret' => null, 'two_factor_enabled' => false])->save();
            $locked->recoveryCodes()->delete();
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            $this->audit->record(new AuditEntry(
                action: AuditAction::StaffTwoFactorReset,
                actorType: 'user',
                actorUserId: $currentActor->id,
                subjectType: 'user',
                subjectId: $locked->id,
                before: ['two_factor_enabled' => true],
                after: ['two_factor_enabled' => false],
            ));

            $this->notifications->toUser(new TwoFactorChanged($locked->id, TwoFactorChanged::RESET_BY_ADMIN), $locked);
        });
    }
}
