<?php

namespace App\Domain\Warehouse;

/**
 * One line of a pick list: one active allocation — `(order line,
 * location, batch)` — of the shipment's order at its location (05.5
 * §5.2). Quantity is carried in base units and split into whole packs of
 * the ordered pack plus loose units, so the screen says "3 outers", not
 * "36 units". `serials` are the units reserved at allocation (04 §6.1).
 */
final class PickLine
{
    /**
     * @param  list<array{id: int, serial_number: string, picked: bool}>  $serials
     */
    public function __construct(
        public readonly int $allocationId,
        public readonly int $orderLineId,
        public readonly int $lineNo,
        public readonly int $skuId,
        public readonly string $skuCode,
        public readonly string $name,
        public readonly ?string $thumbnailUrl,
        public readonly string $packLabel,
        public readonly int $packBaseUnits,
        public readonly int $baseQty,
        public readonly string $trackingMode,
        public readonly ?int $batchId,
        public readonly ?string $batchCode,
        public readonly ?string $expiresOn,
        public readonly ?string $binCode,
        public readonly ?int $walkSequence,
        public readonly string $status,
        public readonly array $serials,
    ) {}

    public function packs(): int
    {
        return intdiv($this->baseQty, $this->packBaseUnits);
    }

    public function looseUnits(): int
    {
        return $this->baseQty % $this->packBaseUnits;
    }

    public function isPicked(): bool
    {
        return $this->status === 'picked';
    }
}
