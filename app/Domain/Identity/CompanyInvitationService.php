<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notices\CompanyInvitationAccepted;
use App\Domain\Notifications\Notices\CompanyInvited;
use App\Domain\Notifications\Notifications;
use App\Domain\Notifications\Recipient;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** 05.13 §9: no membership or user before acceptance; mail only after commit. */
final class CompanyInvitationService
{
    public function invite(Company $company, User $actor, string $email, string $firstName, string $lastName, CompanyMemberSettings $settings): CompanyInvitation
    {
        return DB::transaction(function () use ($company, $actor, $email, $firstName, $lastName, $settings): CompanyInvitation {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('create', [CompanyInvitation::class, $company]);

            // Expired rows still occupy the partial unique index (02 §17.1).
            $previous = CompanyInvitation::query()->where('company_id', $company->id)->where('email', trim($email))
                ->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->first();
            if ($previous !== null) {
                $this->markRevoked($previous, $actor);
            }

            return $this->issue($company, $actor, $email, $firstName, $lastName, $settings);
        });
    }

    public function resend(CompanyInvitation $invitation, User $actor): CompanyInvitation
    {
        return DB::transaction(function () use ($invitation, $actor): CompanyInvitation {
            $company = Company::query()->lockForUpdate()->findOrFail($invitation->company_id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $invitation = CompanyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            Gate::forUser($actor)->authorize('manage', $invitation);
            $this->markRevoked($invitation, $actor);

            return $this->issue($company, $actor, $invitation->email, $invitation->first_name, $invitation->last_name,
                new CompanyMemberSettings(CompanyMemberRole::from($invitation->role), $invitation->order_limit_minor, $invitation->requires_approval));
        });
    }

    public function revoke(CompanyInvitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            Company::query()->lockForUpdate()->findOrFail($invitation->company_id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $invitation = CompanyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            Gate::forUser($actor)->authorize('manage', $invitation);
            $this->markRevoked($invitation, $actor);
        });
    }

    /** Null means normal sign-in is required; never reset an existing password. */
    public function accept(CompanyInvitation $invitation, ?User $actor, ?string $newPassword = null): ?User
    {
        try {
            return DB::transaction(function () use ($invitation, $actor, $newPassword): ?User {
                Company::query()->lockForUpdate()->findOrFail($invitation->company_id);
                // Same company lock serialises acceptance with resend/revoke and member changes.
                $invitation = CompanyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
                $actor = $actor === null ? null : User::query()->lockForUpdate()->findOrFail($actor->id);
                Gate::forUser($actor)->authorize('accept', $invitation);

                if ($actor === null) {
                    if (User::query()->where('email', $invitation->email)->exists()) {
                        return null;
                    }
                    if ($newPassword === null) {
                        return null;
                    }
                    $actor = User::query()->create([
                        'email' => $invitation->email, 'first_name' => $invitation->first_name,
                        'last_name' => $invitation->last_name, 'password_hash' => Hash::make($newPassword), 'status' => 'active',
                    ]);
                    $actor->forceFill(['email_verified_at' => now()])->save();
                }

                // A later invitation never silently changes an existing member's permissions.
                if (! CompanyUser::query()->where('company_id', $invitation->company_id)->where('user_id', $actor->id)->exists()) {
                    CompanyUser::query()->create([
                        'company_id' => $invitation->company_id, 'user_id' => $actor->id,
                        'role' => $invitation->role, 'order_limit_minor' => $invitation->order_limit_minor,
                        'requires_approval' => $invitation->requires_approval,
                    ]);
                }
                $invitation->forceFill(['accepted_at' => now(), 'accepted_by_user_id' => $actor->id])->save();
                $this->record($invitation, $actor, AuditAction::CompanyInvitationAccepted);
                $inviter = User::query()->find($invitation->invited_by_user_id);
                if ($inviter !== null) {
                    (new Notifications)->toUser(new CompanyInvitationAccepted($invitation->id), $inviter);
                }

                return $actor;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent registration/invitation may have created this email on
            // another company. The failed transaction is rolled back; require login.
            if ($actor === null && User::query()->where('email', $invitation->email)->exists()) {
                return null;
            }
            throw $exception;
        }
    }

    private function issue(Company $company, User $actor, string $email, string $firstName, string $lastName, CompanyMemberSettings $settings): CompanyInvitation
    {
        $token = Str::random(64);
        $invitation = CompanyInvitation::query()->create([
            'company_id' => $company->id, 'email' => mb_strtolower(trim($email)),
            'first_name' => trim($firstName), 'last_name' => trim($lastName),
            ...$settings->attributes(), 'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $actor->id, 'expires_at' => now()->addDays(7),
        ]);
        $this->record($invitation, $actor, AuditAction::CompanyInvited);
        (new Notifications)->toRecipient(new CompanyInvited($invitation->id, $token),
            new Recipient($invitation->email, companyId: $company->id, name: $invitation->first_name));

        return $invitation;
    }

    private function markRevoked(CompanyInvitation $invitation, User $actor): void
    {
        $invitation->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
        $this->record($invitation, $actor, AuditAction::CompanyInvitationRevoked);
    }

    private function record(CompanyInvitation $invitation, User $actor, AuditAction $action): void
    {
        (new AuditLogger)->record(new AuditEntry(
            action: $action, actorType: 'user', actorUserId: $actor->id,
            companyId: $invitation->company_id, subjectType: 'company_invitation', subjectId: $invitation->id,
            after: $action === AuditAction::CompanyInvited
                ? ['role' => $invitation->role, 'order_limit_minor' => $invitation->order_limit_minor, 'requires_approval' => $invitation->requires_approval]
                : [],
        ));
    }
}
