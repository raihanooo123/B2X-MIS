<?php

namespace App\Policies;

use App\Models\Rma;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Returns, staff side (05.4 §7.3–7.5, §13). The warehouse books parcels in
 * and inspects them (the stock decision); accounts review proof of sending
 * and problem reports, settle returns, record bank refunds and record a
 * cancellation a customer made by email or phone (the money and customer
 * decisions); admins do both; all three can see returns and their files. A return is never deleted:
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

    /** Dispositioning what arrived; restocking moves stock (05.4 §7.4). */
    public function inspect(User $user, Rma $rma): bool
    {
        return $user->hasAnyRole(self::RECEIVERS);
    }

    /** Settling the return: the refund, repair or replacement (05.4 §13.6). */
    public function resolve(User $user, Rma $rma): bool
    {
        return $user->hasAnyRole(self::PROOF_REVIEWERS);
    }

    /** Approving or rejecting a problem report (05.4 §13.4). */
    public function review(User $user, Rma $rma): bool
    {
        return $user->hasAnyRole(self::PROOF_REVIEWERS);
    }

    /** Recording a refund paid by bank transfer (05.4 §13.6). */
    public function recordRefund(User $user, Rma $rma): bool
    {
        return $user->hasAnyRole(self::PROOF_REVIEWERS);
    }

    /** Recording a cancellation the customer made by email or phone (05.4 §13.3, S6e). */
    public function recordCancellation(User $user): bool
    {
        return $user->hasAnyRole(self::PROOF_REVIEWERS);
    }
}
