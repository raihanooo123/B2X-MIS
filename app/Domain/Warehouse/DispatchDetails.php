<?php

namespace App\Domain\Warehouse;

/**
 * What the dispatcher records (05.5 §7.1). A delivery carries the carrier
 * and tracking number; a collection carries a note of who collected, since
 * identity and signature capture has no column yet.
 */
final class DispatchDetails
{
    public function __construct(
        public readonly ?string $carrier = null,
        public readonly ?string $trackingNumber = null,
        public readonly ?int $parcelCount = null,
        public readonly ?int $totalWeightG = null,
        public readonly ?string $note = null,
        public readonly ?int $actorUserId = null,
    ) {}
}
