<?php

namespace App\Domain\Audit;

enum AuditAction: string
{
    case SignInFailed = 'auth.sign_in_failed';

    public function family(): string
    {
        return match ($this) {
            self::SignInFailed => 'auth',
        };
    }

    /** @return list<string> */
    public function beforeFields(): array
    {
        return match ($this) {
            self::SignInFailed => [],
        };
    }

    /** @return list<string> */
    public function afterFields(): array
    {
        return match ($this) {
            self::SignInFailed => ['identifier_fingerprint', 'key_version'],
        };
    }
}
