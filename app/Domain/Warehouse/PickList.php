<?php

namespace App\Domain\Warehouse;

use App\Models\Order;
use App\Models\Shipment;

/**
 * A shipment's pick list (05.5 §5.1), lines in walk order.
 */
final class PickList
{
    /**
     * @param  list<PickLine>  $lines
     */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly Order $order,
        public readonly array $lines,
    ) {}

    /** Every line picked (or short-picked) — dispatch may proceed (05.5 §12). */
    public function isComplete(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isPicked()) {
                return false;
            }
        }

        return $this->lines !== [];
    }
}
