<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\CompaniesHouseCheckOutcome;
use App\Domain\Accounts\VerificationFailureReason;
use Carbon\CarbonImmutable;

/** One answer from Companies House, before it is written (02 §25.5). */
final readonly class CompanyLookup
{
    /**
     * @param  array<string, mixed>|null  $office
     */
    private function __construct(
        public CompaniesHouseCheckOutcome $outcome,
        public ?string $status = null,
        public ?string $type = null,
        public ?string $name = null,
        public ?array $office = null,
        public ?CarbonImmutable $incorporatedOn = null,
        public ?VerificationFailureReason $failure = null,
    ) {}

    /** @param array<string, mixed>|null $office */
    public static function found(string $status, ?string $type, string $name, ?array $office, ?CarbonImmutable $incorporatedOn): self
    {
        return new self(CompaniesHouseCheckOutcome::Found, $status, $type, $name, $office, $incorporatedOn);
    }

    public static function notFound(): self
    {
        return new self(CompaniesHouseCheckOutcome::NotFound);
    }

    public static function unchecked(VerificationFailureReason $reason): self
    {
        return new self(CompaniesHouseCheckOutcome::Unchecked, failure: $reason);
    }
}
