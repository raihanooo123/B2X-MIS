<?php

namespace App\Domain\Delivery;

use App\Models\DeliveryZone;

/**
 * The carriage for one order to one destination (05.6 §5–6, §8).
 *
 *   - `rated`         — `shippingNetMinor` is the carriage, VAT at `taxRateBp`.
 *   - `free`          — carriage-paid threshold met: 0, whatever the weight.
 *   - `manual_quote`  — the zone is quoted by hand, the consignment has no
 *                       weight data, or no rate band covers it. The order is
 *                       blocked with "we'll quote you" — never charged £0.
 *   - `unserviceable` — the zone does not deliver there (05.6 §10).
 *
 * `reason` says which, in a stable code: `zone_manual_quote`,
 * `missing_weight`, `no_rate_band`, `no_carriage_tax_rate`, `no_zone`,
 * `zone_unserviceable`.
 */
final readonly class DeliveryQuote
{
    public function __construct(
        public string $status,
        public ?DeliveryZone $zone,
        public bool $postcodeRecognised,
        public ?string $method,
        public ?int $weightG,
        public int $shippingNetMinor,
        public ?int $taxRateBp,
        public ?int $rateId,
        public int $carriagePaidThresholdNetMinor,
        public int $shortfallToFreeMinor,
        public ?string $reason = null,
    ) {}

    public function isChargeable(): bool
    {
        return $this->status === 'rated' || $this->status === 'free';
    }
}
