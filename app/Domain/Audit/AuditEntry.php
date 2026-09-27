<?php

namespace App\Domain\Audit;

final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public AuditAction $action,
        public string $actorType,
        public ?int $actorUserId = null,
        public ?int $actingForCompanyId = null,
        public ?int $companyId = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public array $before = [],
        public array $after = [],
        public ?string $reason = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
