<?php

namespace App\Domain\Inventory;

/**
 * One stock identity that cannot cover what was requested against it —
 * the aggregate requested quantity may come from several AllocationLine
 * entries sharing the same (sku, location, batch).
 */
final class AllocationShortfall
{
    public function __construct(
        public readonly int $skuId,
        public readonly int $locationId,
        public readonly ?int $batchId,
        public readonly int $requestedBaseQty,
        public readonly int $availableBaseQty,
    ) {}
}
