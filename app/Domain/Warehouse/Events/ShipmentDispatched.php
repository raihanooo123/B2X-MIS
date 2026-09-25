<?php

namespace App\Domain\Warehouse\Events;

/**
 * 04 §11 `StockDispatched` / 05.12 `shipment.dispatched`: dispatched via
 * DB::afterCommit() once per shipment, never on a replay. The customer
 * notice and invoicing are sent directly by DispatchService after the
 * same commit; commission and the courier manifest will listen here.
 */
final class ShipmentDispatched
{
    public function __construct(
        public readonly int $shipmentId,
        public readonly int $orderId,
        public readonly bool $orderFullyDispatched,
    ) {}
}
