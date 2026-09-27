<?php

namespace App\Policies;

use App\Models\B2bApplication;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * 05.2 §5.4: trade applications are reviewed by the `admin` and
 * `accounts` roles (decided 2026-09-27, 05.12 Q6). Only those roles may
 * grant a credit limit above zero (grantCredit), checked separately so
 * the rule survives any widening of who reviews. Each
 * review ability is one edge of the 05.2 §4 state machine, so an action
 * is only ever offered from the state it leaves. ApplicationReviewService
 * re-checks these against the application row it has locked.
 *
 * Applications entered by staff for a customer with no account
 * (`applicant_user_id` NULL) are listed but cannot be reviewed yet: there
 * is no user to link as owner or to notify.
 */
final class B2bApplicationPolicy
{
    use DeniesDeletion;

    public const REVIEWER_ROLES = ['admin', 'accounts'];

    public const CREDIT_ROLES = ['admin', 'accounts'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::REVIEWER_ROLES);
    }

    public function view(User $user, B2bApplication $application): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, B2bApplication $application): bool
    {
        return false;
    }

    public function startReview(User $user, B2bApplication $application): bool
    {
        return $this->reviews($user, $application, 'submitted');
    }

    public function requestInfo(User $user, B2bApplication $application): bool
    {
        return $this->reviews($user, $application, 'in_review');
    }

    /**
     * info_requested → in_review. 05.2 §5.5 has the applicant's response
     * do this; until that form exists, the reviewer does it once the
     * information has arrived by other means.
     */
    public function resumeReview(User $user, B2bApplication $application): bool
    {
        return $this->reviews($user, $application, 'info_requested');
    }

    public function approve(User $user, B2bApplication $application): bool
    {
        return $this->reviews($user, $application, 'in_review');
    }

    public function reject(User $user, B2bApplication $application): bool
    {
        return $this->reviews($user, $application, 'in_review');
    }

    /**
     * 02 §25.4: re-run the VAT and Companies House checks — reviewers, on
     * an open application that has something to check. Staff-entered
     * applications qualify too: the checks need numbers, not an applicant.
     */
    public function rerunChecks(User $user, B2bApplication $application): bool
    {
        return $user->status === 'active'
            && $this->viewAny($user)
            && in_array($application->status, B2bApplication::OPEN_STATUSES, true)
            && ($application->vat_number !== null || $application->registration_number !== null);
    }

    /** 05.2 §5.6 step 2 with `credit_limit_minor` above zero. */
    public function grantCredit(User $user, B2bApplication $application): bool
    {
        return $this->approve($user, $application) && $user->hasAnyRole(self::CREDIT_ROLES);
    }

    private function reviews(User $user, B2bApplication $application, string $from): bool
    {
        return $user->status === 'active'
            && $this->viewAny($user)
            && $application->applicant_user_id !== null
            && $application->status === $from;
    }
}
