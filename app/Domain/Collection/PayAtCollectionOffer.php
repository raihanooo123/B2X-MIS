<?php

namespace App\Domain\Collection;

use Carbon\CarbonImmutable;

/**
 * What checkout preview tells the buyer about paying cash at collection
 * for the chosen slot (05.6 §7A.3): offered or not, and why not. The
 * deadline is the slot's end plus the grace period (§7A.5).
 */
final readonly class PayAtCollectionOffer
{
    public function __construct(
        public bool $available,
        public ?string $rule,
        public ?string $reason,
        public int $limitGrossMinor,
        public CarbonImmutable $paymentDueBy,
    ) {}
}
