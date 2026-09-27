<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notices\PasswordReset as PasswordResetNotice;
use App\Domain\Notifications\Notifications;
use App\Http\Requests\Concerns\AuthFields;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffOnboardingService
{
    private const ROLES = ['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        $data = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => AuthFields::email(),
            'role_codes' => ['required', 'array', 'min:1'],
            'role_codes.*' => ['required', 'string', Rule::in(self::ROLES)],
        ])->validate();

        $email = mb_strtolower(trim($data['email']));
        $roleCodes = array_values(array_unique($data['role_codes']));
        $roles = Role::query()->whereIn('code', $roleCodes)->get()->keyBy('code');
        if ($roles->count() !== count($roleCodes)) {
            throw ValidationException::withMessages(['role_codes' => 'One or more staff roles are not configured.']);
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to an account.']);
        }

        try {
            return DB::transaction(function () use ($data, $email, $roleCodes, $roles, $actor): User {
                $staff = User::query()->create([
                    'first_name' => trim($data['first_name']),
                    'last_name' => trim($data['last_name']),
                    'email' => $email,
                    'password_hash' => null,
                    'status' => 'pending',
                ]);

                $this->audit($actor, $staff, AuditAction::StaffCreated, ['status' => 'pending']);
                foreach ($roleCodes as $code) {
                    $role = $roles->get($code);
                    if ($role === null) {
                        throw ValidationException::withMessages(['role_codes' => 'One or more staff roles are not configured.']);
                    }
                    RoleUser::create([
                        'role_id' => $role->id,
                        'user_id' => $staff->id,
                        'granted_by_user_id' => $actor->id,
                    ]);
                    $this->audit($actor, $staff, AuditAction::StaffRoleGranted, ['role' => $code]);
                }

                $this->requestLink($staff, $actor);

                return $staff;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'users_email_uq')) {
                throw ValidationException::withMessages(['email' => 'This email already belongs to an account.']);
            }

            throw $exception;
        }
    }

    public function resendLink(User $staff, User $actor): void
    {
        DB::transaction(function () use ($staff, $actor): void {
            $locked = User::query()->lockForUpdate()->findOrFail($staff->id);
            Gate::forUser($actor)->authorize('resendStaffOnboarding', $locked);

            if ($this->tokens()->recentlyCreatedToken($locked)) {
                throw ValidationException::withMessages(['email' => 'An onboarding link was sent recently. Please wait before resending.']);
            }

            $this->requestLink($locked, $actor);
        });
    }

    private function requestLink(User $staff, User $actor): void
    {
        $token = $this->tokens()->create($staff);
        $this->notifications->toUser(new PasswordResetNotice($staff->id, $token), $staff);
        $this->audit($actor, $staff, AuditAction::StaffOnboardingRequested, ['channel' => 'email']);
    }

    /** @param array<string, string> $after */
    private function audit(User $actor, User $staff, AuditAction $action, array $after): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            actorType: 'user',
            actorUserId: $actor->id,
            subjectType: 'user',
            subjectId: $staff->id,
            after: $after,
        ));
    }

    private function tokens(): TokenRepositoryInterface
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker();

        return $broker->getRepository();
    }
}
