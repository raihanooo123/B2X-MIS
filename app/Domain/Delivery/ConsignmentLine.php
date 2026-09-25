<?php

namespace App\Domain\Delivery;

/**
 * One line of a consignment, as the weigher needs it — pack data (02 §5.6)
 * and the SKU's unit weight as fallback. Built from cart lines.
 */
final readonly class ConsignmentLine
{
    public function __construct(
        public int $packQty,
        public int $baseQty,
        public ?int $packGrossWeightG,
        public ?int $skuUnitWeightG,
        public ?int $packsPerLayer = null,
        public ?int $layersPerPallet = null,
    ) {}
}
