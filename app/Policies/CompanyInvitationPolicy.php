<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class CompanyInvitationPolicy
{
    public function create(User $user, Company $company): bool
    {
        return $user->can('manageMembers', $company);
    }

    public function manage(User $user, CompanyInvitation $invitation): bool
    {
        return $invitation->accepted_at === null && $invitation->revoked_at === null
            && $user->can('manageMembers', $invitation->company);
    }

    public function accept(?User $user, CompanyInvitation $invitation): Response
    {
        if (! $invitation->isOpen()) {
            return Response::deny('This invitation is no longer available. Ask for a new invitation.');
        }
        if ($user === null) {
            return Response::allow();
        }
        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            return Response::deny('Sign out and use the email address this invitation was sent to.');
        }

        return $user->status === 'active' && ! $user->isStaff()
            ? Response::allow()
            : Response::deny('This invitation cannot be accepted with this account.');
    }
}
