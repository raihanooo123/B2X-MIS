<?php

namespace App\Policies;

use App\Models\Rma;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Returns, staff side (05.4 §7.3, §13.5). The warehouse books parcels in;
 * accounts review a consumer's proof of sending and may reject it; both,
 * and admins, can see returns and their proof. A return is never deleted:
 * it is the record of a statutory cancellation. Customers reach their own
 * returns through their order (OrderPolicy), not this policy.
 */
final class RmaPolicy
{
    use DeniesDeletion;

    /** @var list<string> */
    public const VIEWERS = ['admin', 'warehouse', 'accounts'];

    /** @var list<string> */
    public const RECEIVERS = ['admin', 'warehouse'];

    /** @var list<string> */
    public const PROOF_REVIEWERS = ['admin', 'accounts'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::VIEWERS);
    }

    public function view(User $user, Rma $rma): bool
    {
        return $this->viewAny($user);
    }

    /** Booking the parcel in (05.4 §7.3). */
    public function receive(User $user, Rma $rma): bool
    {
        return $user->hasAnyRole(self::RECEIVERS);
    }

    /** Rejecting invalid proof of sending (05.4 §13.5). */
    public function rejectProof(User $user, Rma $rma): bool
    {
        return $user->hasAnyRole(self::PROOF_REVIEWERS);
    }
}
