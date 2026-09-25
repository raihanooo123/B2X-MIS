<?php

namespace App\Domain\Warehouse\Events;

/**
 * 05.5 §5.4 step 4: a discrepancy raised for investigation. The durable
 * record is the `adjustment` movement itself (reason, actor, allocation);
 * this event is what a stock-accuracy report or alert listens to.
 */
final class ShortPickRecorded
{
    public function __construct(
        public readonly int $allocationId,
        public readonly int $shipmentId,
        public readonly int $shortfallBaseQty,
        public readonly string $reason,
        public readonly int $replannedBaseQty,
        public readonly ?int $actorUserId,
    ) {}
}
