<?php

namespace App\Domain\Ordering;

use App\Domain\Identity\CompanyMemberships;
use App\Domain\Identity\Exceptions\CompanyChoiceRequiredException;
use App\Models\User;

/**
 * Maps an authenticated user onto the cart identity they act as
 * (05.13 §6.3, §8):
 *
 *   - no active company membership → the user (a public customer, an
 *     applicant, or staff);
 *   - exactly one → that company;
 *   - several → the company chosen for this session, which the caller
 *     passes in (App\Http\Support\ActingCompany reads it from the
 *     session). With no valid choice this throws rather than guessing —
 *     the lowest-id placeholder it replaces put a buyer's lines in
 *     whichever account happened to be created first.
 *
 * "Active" is CompanyMemberships' rule: approved or suspended companies.
 */
final class CartOwnerResolver
{
    /**
     * @throws CompanyChoiceRequiredException
     */
    public function forUser(User $user, ?int $chosenCompanyId = null): CartOwner
    {
        $companyIds = CompanyMemberships::ids($user);

        if ($companyIds === []) {
            return CartOwner::user($user->id);
        }

        if (count($companyIds) === 1) {
            return CartOwner::company($companyIds[0], $user->id);
        }

        if ($chosenCompanyId !== null && in_array($chosenCompanyId, $companyIds, true)) {
            return CartOwner::company($chosenCompanyId, $user->id);
        }

        throw new CompanyChoiceRequiredException;
    }
}
