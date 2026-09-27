<?php

namespace App\Domain\Audit;

enum AuditAction: string
{
    case SignInFailed = 'auth.sign_in_failed';

    case StaffCreated = 'auth.staff_created';

    case StaffOnboardingRequested = 'auth.staff_onboarding_requested';

    case StaffRoleGranted = 'permission.staff_role_granted';

    public function family(): string
    {
        return match ($this) {
            self::SignInFailed, self::StaffCreated, self::StaffOnboardingRequested => 'auth',
            self::StaffRoleGranted => 'permission',
        };
    }

    /** @return list<string> */
    public function beforeFields(): array
    {
        return match ($this) {
            self::SignInFailed, self::StaffCreated, self::StaffOnboardingRequested, self::StaffRoleGranted => [],
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
        };
    }
}
