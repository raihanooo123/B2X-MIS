<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.4–25.5 `failure_reason`: why a check is `unchecked`. The first
 * three are transient — the job retries them — and the last two are final.
 */
enum VerificationFailureReason: string
{
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case NotConfigured = 'not_configured';
    case UnexpectedResponse = 'unexpected_response';

    public function isTransient(): bool
    {
        return in_array($this, [self::Timeout, self::Unavailable, self::RateLimited], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Timeout => 'the service timed out',
            self::Unavailable => 'the service was unavailable',
            self::RateLimited => 'the service was busy (rate-limited)',
            self::NotConfigured => 'no credentials are configured',
            self::UnexpectedResponse => 'the service gave an unexpected answer',
        };
    }
}
