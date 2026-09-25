<?php

namespace App\Domain\Delivery;

/**
 * Carriage from one `delivery_rates` row (05.6 §5.3): the band's price,
 * any per-kg surcharge above the band's lower bound, and the rate's own
 * tax class — carriage is its own taxable supply (05.6 §5.2).
 */
final readonly class RatedCarriage
{
    public function __construct(
        public int $rateId,
        public string $method,
        public int $bandLowerG,
        public int $priceNetMinor,
        public int $surchargeNetMinor,
        public int $taxClassId,
    ) {}

    public function netMinor(): int
    {
        return $this->priceNetMinor + $this->surchargeNetMinor;
    }
}
