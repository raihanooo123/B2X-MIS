<?php

namespace App\Domain\Ordering;

use App\Models\CompanyUser;
use App\Models\User;

/**
 * Maps an authenticated user onto the cart identity they act as.
 *
 * A user who belongs to several companies acts for the lowest
 * `company_id` — deterministic, but a placeholder: there is no
 * active-company switcher in any spec yet (05.13 auth/onboarding is
 * unwritten, ROADMAP §23). When one exists, this is the single place
 * that needs to learn about it.
 */
final class CartOwnerResolver
{
    public function forUser(User $user): CartOwner
    {
        $companyId = CompanyUser::query()
            ->where('user_id', $user->id)
            ->orderBy('company_id')
            ->value('company_id');

        return $companyId !== null
            ? CartOwner::company((int) $companyId, $user->id)
            : CartOwner::user($user->id);
    }
}
