<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.1: what an applicant accepted, and from where. `ip` is the
 * client address as the request resolves it, which honours the trusted
 * proxies in `config/trustedproxy.php`.
 */
final readonly class AcceptedTerms
{
    /** Longer user agents are truncated: they are evidence, not a key. */
    private const USER_AGENT_MAX = 1000;

    public ?string $userAgent;

    public function __construct(
        public int $termsVersionId,
        public ?string $ip,
        ?string $userAgent,
    ) {
        $this->userAgent = $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX);
    }
}
