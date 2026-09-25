<?php

namespace App\Domain\Warehouse\Events;

/**
 * 04 §11 / 05.5 §4.6 — dispatched via DB::afterCommit() once per booked
 * receipt line, never on a replay. Consumers (none built yet): back-in-
 * stock notifications when `available` goes from zero to positive (04
 * §8), search reindex, PO progress, backorder auto-allocation in
 * `placed_at` order.
 */
final class StockReceived
{
    public function __construct(
        public readonly int $goodsReceiptLineId,
        public readonly int $skuId,
        public readonly int $locationId,
        public readonly ?int $batchId,
        public readonly int $baseQty,
    ) {}
}
