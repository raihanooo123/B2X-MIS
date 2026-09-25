<?php

namespace App\Domain\Delivery;

/**
 * A weighed consignment. `weightG` is null when any line has no weight
 * data — the consignment is then **unrateable** (05.6 §5.1): routed to a
 * manual quote, never rated on a guess.
 */
final readonly class Consignment
{
    public function __construct(
        public ?int $weightG,
        public string $method,
        /** Pallets' worth, in millionths (1_000_000 = one full pallet). */
        public int $palletEquivalentPpm,
    ) {}

    public function isRateable(): bool
    {
        return $this->weightG !== null;
    }
}
