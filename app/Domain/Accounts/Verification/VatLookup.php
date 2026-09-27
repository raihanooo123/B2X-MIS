<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\VatCheckOutcome;
use App\Domain\Accounts\VerificationFailureReason;
use Carbon\CarbonImmutable;

/** One answer from HMRC or VIES, before it is written (02 §25.4). */
final readonly class VatLookup
{
    /**
     * @param  array<string, mixed>|null  $address
     */
    private function __construct(
        public VatCheckOutcome $outcome,
        public ?string $name = null,
        public ?array $address = null,
        public ?string $consultationNumber = null,
        public ?CarbonImmutable $processedAt = null,
        public ?VerificationFailureReason $failure = null,
    ) {}

    /** @param array<string, mixed>|null $address */
    public static function valid(?string $name, ?array $address, ?string $consultationNumber, CarbonImmutable $processedAt): self
    {
        return new self(VatCheckOutcome::Valid, $name, $address, $consultationNumber, $processedAt);
    }

    public static function notFound(?CarbonImmutable $processedAt = null): self
    {
        return new self(VatCheckOutcome::NotFound, processedAt: $processedAt);
    }

    public static function unchecked(VerificationFailureReason $reason): self
    {
        return new self(VatCheckOutcome::Unchecked, failure: $reason);
    }
}
