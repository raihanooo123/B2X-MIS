<?php

namespace App\Domain\Ordering\Exceptions;

use RuntimeException;

/**
 * CheckoutService currently only allocates SKUs with `tracking_mode =
 * 'none'` (single stock_levels row, batch_id IS NULL). Batch selection
 * (doc 04 §5 — FEFO/FIFO strategy, expiry filtering, SKIP LOCKED
 * candidate selection, splitting across batches) is a substantial,
 * separate algorithm this task did not build. Which SKUs enable
 * batch/serial tracking at launch is itself an open decision (CLAUDE.md,
 * "Open decisions") — thrown loudly rather than guessed at.
 */
final class BatchTrackedCheckoutNotSupportedException extends RuntimeException
{
    public function __construct(public readonly int $skuId)
    {
        parent::__construct(
            "Sku {$skuId} is batch/serial tracked — checkout does not yet implement doc 04 §5 batch selection."
        );
    }
}
