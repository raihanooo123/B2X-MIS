<?php

namespace App\Domain\Billing;

use App\Models\OrderLine;

/**
 * One order line's share of a per-shipment invoice (05.5 §7.3 as amended
 * 2026-09-25): the base quantity shipped and the pence it carries. All
 * integers; see ShipmentInvoiceShares for how they are apportioned.
 */
final class ShipmentLineShare
{
    public function __construct(
        public readonly OrderLine $orderLine,
        public readonly int $shippedBaseQty,
        public readonly int $netMinor,
        public readonly int $taxMinor,
        public readonly int $discountMinor,
    ) {}
}
