<?php

namespace App\Domain\Storefront;

use Carbon\CarbonImmutable;

/**
 * The collection arrangement the pre-contract information states for a
 * collection order (05.15 §7.1, 05.6 §7A.3). `cashDueBy` is set only for
 * pay at collection: the slot's end plus the grace period.
 */
final readonly class CollectionTerms
{
    public function __construct(
        public string $slotLabel,
        public ?string $locationName,
        public ?int $cashGrossMinor = null,
        public ?CarbonImmutable $cashDueBy = null,
    ) {}

    public function paysCash(): bool
    {
        return $this->cashGrossMinor !== null && $this->cashDueBy !== null;
    }
}
