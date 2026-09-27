<?php

namespace App\Domain\Audit;

enum AuditAction: string
{
    case SignInFailed = 'auth.sign_in_failed';

    case StaffCreated = 'auth.staff_created';

    case StaffOnboardingRequested = 'auth.staff_onboarding_requested';

    case StaffRoleGranted = 'permission.staff_role_granted';

    case StaffRoleRevoked = 'permission.staff_role_revoked';

    public function family(): string
    {
        return match ($this) {
            self::SignInFailed, self::StaffCreated, self::StaffOnboardingRequested => 'auth',
            self::StaffRoleGranted, self::StaffRoleRevoked => 'permission',
        };
    }

    /** @return list<string> */
    public function beforeFields(): array
    {
        return match ($this) {
            self::SignInFailed, self::StaffCreated, self::StaffOnboardingRequested, self::StaffRoleGranted => [],
            self::StaffRoleRevoked => ['role', 'granted_by_user_id'],
        };
    }

    /** @return list<string> */
    public function afterFields(): array
    {
        return match ($this) {
            self::SignInFailed => ['identifier_fingerprint', 'key_version'],
            self::StaffCreated => ['status'],
            self::StaffOnboardingRequested => ['channel'],
            self::StaffRoleGranted => ['role'],
            self::StaffRoleRevoked => [],
        };
    }
}
