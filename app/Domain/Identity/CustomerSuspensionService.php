<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * 05.13 §4.2, §13.3: an administrator suspends or reinstates a customer
 * user (no `role_user` rows). Suspension ends every session and discards
 * any password-reset token in the same transaction as the status change
 * and its audit row. The user's companies are untouched — company
 * suspension is a separate, credit-only state (05.2 §9).
 *
 * 05.13 §9 ⚑5 (decided 2026-09-27): an `approved` or `suspended` company
 * (CompanyMemberships::ACTIVE_STATUSES) keeps at least one active owner, so
 * its last active owner cannot be suspended. Applied, rejected and closed
 * companies are exempt.
 *
 * Locks: the `admin` row of `roles` (as StaffSuspensionService, so the
 * actor's authority is read after any competing role change commits),
 * then every company the user belongs to, ascending by id, then the
 * subject's `users` row. Companies precede users, matching CLAUDE.md
 * invariant 6's global order; two administrators suspending the last two
 * owners of one company serialise on that company's row, and the second
 * sees the first's suspension.
 */
final class CustomerSuspensionService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function suspend(User $customer, User $actor): void
    {
        $this->change($customer, $actor, 'suspendCustomer', function (User $locked) use ($actor): void {
            $orphaned = $this->companiesLeftWithoutActiveOwner($locked);
            if ($orphaned !== []) {
                throw ValidationException::withMessages(['status' => 'This user is the last active owner of '.implode(', ', $orphaned).'. Make another user an owner first.']);
            }

            $locked->forceFill(['status' => 'suspended'])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $this->tokens()->delete($locked);

            $this->record($actor, $locked, AuditAction::CustomerSuspended, 'active', 'suspended');
        });
    }

    public function reinstate(User $customer, User $actor): void
    {
        $this->change($customer, $actor, 'reinstateCustomer', function (User $locked) use ($actor): void {
            $locked->forceFill(['status' => 'active'])->save();

            $this->record($actor, $locked, AuditAction::CustomerReinstated, 'suspended', 'active');
        });
    }

    /**
     * @param  callable(User): void  $apply
     */
    private function change(User $customer, User $actor, string $ability, callable $apply): void
    {
        DB::transaction(function () use ($customer, $actor, $ability, $apply): void {
            if (Role::query()->where('code', 'admin')->lockForUpdate()->first() === null) {
                throw ValidationException::withMessages(['status' => 'Staff roles are not configured.']);
            }

            $companyIds = $this->membershipCompanyIds($customer);
            Company::query()->whereIn('id', $companyIds)->orderBy('id')->lockForUpdate()->get(['id']);

            $locked = User::query()->lockForUpdate()->findOrFail($customer->id);
            if ($this->membershipCompanyIds($locked) !== $companyIds) {
                throw ValidationException::withMessages(['status' => 'This user\'s company memberships changed. Try again.']);
            }

            $currentActor = User::query()->findOrFail($actor->id);
            if ($currentActor->id === $locked->id) {
                throw new AuthorizationException('Administrators cannot suspend or reinstate themselves.');
            }
            Gate::forUser($currentActor)->authorize($ability, $locked);

            $apply($locked);
        });
    }

    /** @return list<int> */
    private function membershipCompanyIds(User $user): array
    {
        return array_values(CompanyUser::query()->where('user_id', $user->id)->orderBy('company_id')
            ->pluck('company_id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @return list<string> */
    private function companiesLeftWithoutActiveOwner(User $owner): array
    {
        return array_values(DB::table('company_users as mine')
            ->join('companies', 'companies.id', '=', 'mine.company_id')
            ->where('mine.user_id', $owner->id)
            ->where('mine.role', 'owner')
            ->whereIn('companies.status', CompanyMemberships::ACTIVE_STATUSES)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('company_users as other')
                ->join('users', 'users.id', '=', 'other.user_id')
                ->whereColumn('other.company_id', 'mine.company_id')
                ->where('other.role', 'owner')
                ->where('other.user_id', '<>', $owner->id)
                ->where('users.status', 'active')
                ->whereNull('users.deleted_at'))
            ->orderBy('companies.name')
            ->pluck('companies.name')
            ->map(fn ($name): string => (string) $name)
            ->all());
    }

    private function record(User $actor, User $customer, AuditAction $action, string $from, string $to): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            actorType: 'user',
            actorUserId: $actor->id,
            subjectType: 'user',
            subjectId: $customer->id,
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
