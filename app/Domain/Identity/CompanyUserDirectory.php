<?php

namespace App\Domain\Identity;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;

/** Explicit storefront payloads: no token hashes or account-existence lookups. */
final class CompanyUserDirectory
{
    /** @return array<string, mixed>|null */
    public static function management(User $actor, ?Company $company): ?array
    {
        if ($company === null || ! $actor->can('manageMembers', $company)) {
            return null;
        }

        return [
            'company_id' => $company->public_id,
            'members' => $company->users()->orderBy('first_name')->get()->map(fn (User $user): array => [
                'id' => $user->public_id, 'name' => trim($user->first_name.' '.$user->last_name), 'email' => $user->email,
                'status' => $user->status, 'role' => $user->pivot->role,
                // Strings keep bigint pence exact in a browser input.
                'order_limit_minor' => $user->pivot->order_limit_minor === null ? '' : (string) $user->pivot->order_limit_minor,
                'requires_approval' => $user->pivot->requires_approval,
            ])->all(),
            'invitations' => CompanyInvitation::query()->where('company_id', $company->id)->orderByDesc('id')->get()
                ->map(fn (CompanyInvitation $invitation): array => [
                    'id' => $invitation->public_id, 'email' => $invitation->email, 'role' => $invitation->role,
                    'state' => $invitation->accepted_at !== null ? 'Accepted' : ($invitation->revoked_at !== null ? 'Revoked' : ($invitation->isOpen() ? 'Invited' : 'Expired')),
                    'manageable' => $invitation->accepted_at === null && $invitation->revoked_at === null,
                ])->all(),
        ];
    }

    /** @return list<array{id: string, company: string, role: string}> */
    public static function pending(User $user): array
    {
        if (! $user->hasVerifiedEmail() || $user->isStaff()) {
            return [];
        }

        return CompanyInvitation::query()->with('company')->where('email', $user->email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('id')->get()
            ->map(fn (CompanyInvitation $invitation): array => [
                'id' => $invitation->public_id, 'company' => $invitation->company->name, 'role' => $invitation->role,
            ])->all();
    }
}
