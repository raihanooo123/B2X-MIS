<?php

namespace App\Domain\Audit;

use Illuminate\Http\Request;

/**
 * 07 §6.5: the IP and user agent an audit entry records. One per HTTP
 * request (a scoped binding in AppServiceProvider), taken from the request
 * — so the IP honours `config/trustedproxy.php` — and empty outside one: a
 * console command or queue worker has no client, and recording its
 * placeholder 127.0.0.1 would misstate where an action came from.
 */
final readonly class AuditContext
{
    /** User agents are evidence, not keys; longer ones are cut. */
    private const USER_AGENT_MAX = 1000;

    public ?string $userAgent;

    public function __construct(
        public ?string $ip = null,
        ?string $userAgent = null,
    ) {
        $this->userAgent = $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX);
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->ip(), $request->userAgent());
    }

    public static function none(): self
    {
        return new self;
    }
}
