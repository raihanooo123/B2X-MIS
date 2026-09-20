<?php

namespace App\Domain\Inventory;

/**
 * One requested reservation: reserve $baseQty of $skuId at $locationId
 * (optionally from a specific $batchId) against $orderLineId.
 *
 * Doc 02 §7.4: one order line is routinely satisfied from several
 * batches — pass multiple AllocationLine instances with the same
 * orderLineId but different batchId to express that.
 */
final class AllocationLine
{
    public function __construct(
        public readonly int $orderLineId,
        public readonly int $skuId,
        public readonly int $locationId,
        public readonly ?int $batchId,
        public readonly int $baseQty,
    ) {}

    public function identityKey(): string
    {
        return $this->skuId.':'.$this->locationId.':'.($this->batchId ?? 'null');
    }
}
