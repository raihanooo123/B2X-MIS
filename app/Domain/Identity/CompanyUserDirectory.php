<?php

namespace App\Domain\Identity;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
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

        $memberships = CompanyUser::query()->where('company_id', $company->id)->get();
        $users = User::query()->whereIn('id', $memberships->pluck('user_id'))->get()->keyBy('id');

        $members = [];
        foreach ($memberships as $membership) {
            $user = $users->get($membership->user_id);
            if ($user instanceof User) {
                $members[] = [
                    'id' => $user->public_id, 'name' => trim($user->first_name.' '.$user->last_name), 'email' => $user->email,
                    'status' => $user->status, 'role' => $membership->role,
                    // Pounds as a string, exactly as the edit form takes it.
                    'order_limit' => CompanyMemberSettings::minorToPounds($membership->order_limit_minor) ?? '',
                    'requires_approval' => $membership->requires_approval,
                    'is_you' => $user->id === $actor->id,
                ];
            }
        }
        usort($members, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'company_id' => $company->public_id,
            'members' => $members,
            'invitations' => CompanyInvitation::query()->where('company_id', $company->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (CompanyInvitation $invitation): array => [
                    'id' => $invitation->public_id, 'email' => $invitation->email, 'role' => $invitation->role,
                    'order_limit' => CompanyMemberSettings::minorToPounds($invitation->order_limit_minor),
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

        return array_values(CompanyInvitation::query()->with('company')->where('email', $user->email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('id')->get()
            ->map(fn (CompanyInvitation $invitation): array => [
                'id' => $invitation->public_id, 'company' => $invitation->company->name, 'role' => $invitation->role,
            ])->all());
    }
}
