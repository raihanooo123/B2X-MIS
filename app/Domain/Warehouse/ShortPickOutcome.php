<?php

namespace App\Domain\Warehouse;

/**
 * A short pick's result (05.5 §5.4): what was picked, what the ledger
 * wrote off as never there, and how much of that shortfall was re-planned
 * onto other stock versus left to backorder.
 */
final class ShortPickOutcome
{
    public function __construct(
        public readonly int $pickedBaseQty,
        public readonly int $shortfallBaseQty,
        public readonly int $replannedBaseQty,
    ) {}

    public function backorderedBaseQty(): int
    {
        return $this->shortfallBaseQty - $this->replannedBaseQty;
    }
}
