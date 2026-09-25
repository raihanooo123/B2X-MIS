<?php

namespace App\Domain\Warehouse;

/**
 * What the operative says, at close, about a PO line whose received
 * quantity differs from what was ordered (02 §23.3). A reason makes the
 * line final. A null reason means "remainder expected", which is allowed
 * for an under-receipt only: the line stays open and the PO
 * `part_received`.
 */
final class VarianceDecision
{
    public function __construct(
        public readonly int $purchaseOrderLineId,
        public readonly ?VarianceReason $reason,
    ) {}

    public static function remainderExpected(int $purchaseOrderLineId): self
    {
        return new self($purchaseOrderLineId, null);
    }
}
