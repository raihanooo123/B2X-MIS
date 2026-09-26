<?php

namespace App\Domain\Ordering\Exceptions;

use RuntimeException;

/**
 * Legacy exception name retained for the existing API error code.
 * Batch-only SKUs now select eligible batches during allocation; serial
 * and batch-and-serial SKUs still require individual serial reservation,
 * and a batch SKU with allocation_strategy 'none' has no order to select in.
 */
final class BatchTrackedCheckoutNotSupportedException extends RuntimeException
{
    public function __construct(public readonly int $skuId)
    {
        parent::__construct(
            "Sku {$skuId} is serial tracked or has no batch allocation strategy — checkout cannot allocate it."
        );
    }
}
