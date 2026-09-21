<?php

namespace App\Policies;

use App\Models\User;

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
    public function accessAdminPanel(User $user): bool
    {
        return $user->roles()->exists();
    }
}
