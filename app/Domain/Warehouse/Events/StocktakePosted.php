<?php

namespace App\Domain\Warehouse\Events;

/**
 * 04 §11 `StockAdjusted` for a stocktake: dispatched via DB::afterCommit()
 * once, when a stocktake posts with at least its status change. Consumers
 * (none yet): the audit log, low-stock checks, a variance report.
 */
final class StocktakePosted
{
    public function __construct(
        public readonly int $stocktakeId,
        public readonly int $movementCount,
    ) {}
}
