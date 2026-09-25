<?php

namespace App\Domain\Warehouse;

use App\Models\Shipment;

/**
 * What DispatchService::dispatch() did. `replayed` is true when the
 * shipment had already been dispatched: nothing was written (05.5 §10).
 */
final class DispatchOutcome
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly bool $replayed,
        public readonly bool $orderFullyDispatched,
    ) {}
}
