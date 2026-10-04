<?php

namespace App\Policies;

use App\Domain\Storefront\PublicCustomer;
use App\Models\User;
use App\Policies\Concerns\DeniesDeletion;

/**
 * Doc 02 §14.1: `role_user` governs "internal staff authorisation —
 * FilamentPHP admin gating and Policy checks" — both halves of that
 * sentence meet here. CLAUDE.md: FilamentPHP resources "go through the
 * same Policies as everything else — no separate authorisation path,"
 * so panel entry itself is gated through this Policy (auto-discovered
 * by Laravel for the `User` model, per its `{Model}Policy` naming
 * convention — no manual registration needed) rather than an ad hoc
 * check inlined in `User::canAccessPanel()`.
 *
 * `role_user` is exclusively staff roles (the six-code closed list,
 * `roles_code_chk`) — `company_users` is the separate B2B customer-side
 * role table, never conflated (§14.1's own note). Holding any row in
 * `role_user` at all is therefore exactly "is staff"; no further
 * role-code filtering is needed for a panel-entry gate.
 */
final class UserPolicy
{
    use DeniesDeletion;

    public function publicShopping(User $user): bool
    {
        return PublicCustomer::eligible($user) && $user->hasVerifiedEmail();
    }

    public function accessAdminPanel(User $user): bool
    {
        return $user->roles()->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin']);
    }

    public function view(User $user, User $staff): bool
    {
        return $this->viewAny($user) && $staff->roles()->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $staff): bool
    {
        return false;
    }

    /**
     * 05.13 §5.3: an active administrator grants or revokes another staff
     * member's `role_user` rows. Never their own, never a customer (no
     * implicit conversion to staff) and never a suspended or closed
     * account — those lifecycle actions are separate slices.
     * StaffRoleService re-checks this under its row locks and adds the
     * last-active-admin and final-role safeguards.
     */
    public function manageStaffRoles(User $user, User $staff): bool
    {
        return in_array($staff->status, ['pending', 'active'], true)
            && $this->managesOtherStaff($user, $staff);
    }

    /**
     * 05.13 §4.2: an active administrator suspends another active staff
     * member, or reinstates a suspended one. Never themselves. Customer
     * suspension is a separate slice. StaffSuspensionService re-checks
     * this under the same locks as StaffRoleService.
     */
    public function suspendStaff(User $user, User $staff): bool
    {
        return $this->managesOtherStaff($user, $staff) && $staff->status === 'active';
    }

    public function reinstateStaff(User $user, User $staff): bool
    {
        return $this->managesOtherStaff($user, $staff)
            && $staff->status === 'suspended'
            && $staff->password_hash !== null;
    }

    /**
     * 05.13 §12.3: an active administrator resets another active staff
     * member's 2FA after a lost device. Never their own, never a customer
     * (a customer reset needs the identity checks of ⚑9) and never a user
     * with nothing enrolled. StaffTwoFactorResetService re-checks this
     * under the same locks as StaffRoleService.
     */
    public function resetStaffTwoFactor(User $user, User $staff): bool
    {
        return $this->managesOtherStaff($user, $staff)
            && $staff->status === 'active'
            && $staff->two_factor_enabled;
    }

    public function resendStaffOnboarding(User $user, User $staff): bool
    {
        return $this->view($user, $staff)
            && $staff->status === 'pending'
            && $staff->password_hash === null;
    }

    /**
     * Customer users (05.13 §4.1): no `role_user` rows. Admin-only and
     * read-only apart from suspension; a user holding any staff role is
     * managed from the Staff resource instead, never here.
     */
    public function viewAnyCustomers(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function viewCustomer(User $user, User $customer): bool
    {
        return $this->viewAnyCustomers($user) && ! $customer->roles()->exists();
    }

    /**
     * 05.13 §4.2: an active administrator suspends an active customer user,
     * or reinstates a suspended one. CustomerSuspensionService re-checks
     * this under lock and adds the last-active-owner guard (§9 ⚑5).
     */
    public function suspendCustomer(User $user, User $customer): bool
    {
        return $this->managesCustomer($user, $customer) && $customer->status === 'active';
    }

    public function reinstateCustomer(User $user, User $customer): bool
    {
        return $this->managesCustomer($user, $customer)
            && $customer->status === 'suspended'
            && $customer->password_hash !== null;
    }

    private function managesCustomer(User $user, User $customer): bool
    {
        return $user->status === 'active'
            && $user->id !== $customer->id
            && $this->viewCustomer($user, $customer);
    }

    private function managesOtherStaff(User $user, User $staff): bool
    {
        return $user->status === 'active'
            && $user->id !== $staff->id
            && $this->view($user, $staff);
    }
}
