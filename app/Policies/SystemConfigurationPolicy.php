<?php

namespace App\Policies;

use App\Models\User;

/**
 * 02 §2.7 configuration is changed by an active administrator only. The
 * storefront branding (05.15 §3.1) is the first settings screen; other
 * configuration screens reuse this policy.
 */
final class SystemConfigurationPolicy
{
    public function manageStorefront(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['admin']);
    }
}
