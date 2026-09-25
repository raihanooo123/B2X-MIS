<?php

namespace App\Domain\Delivery;

use App\Models\DeliveryZone;

/**
 * Where a delivery address falls. `recognised` is false when the postcode
 * matched no rule and fell back to the mainland (05.6 §10: "Mainland
 * fallback, and the address flagged for review. Never blocked").
 * `zone` is null only when no zone exists at all for the destination —
 * a country with no zone configured.
 */
final readonly class ZoneResolution
{
    public function __construct(
        public ?DeliveryZone $zone,
        public bool $recognised,
    ) {}
}
