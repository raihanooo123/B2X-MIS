<?php

namespace App\Domain\Warehouse;

use Carbon\CarbonImmutable;

/**
 * One receipt entry as the operative confirmed it (05.5 §4.2 steps 2–5).
 * Quantity is in packs; the base quantity is `packQty × pack.base_units`,
 * computed by GoodsInService from the pack, never taken from the client.
 *
 * `clientToken` is the request's Idempotency-Key — the third column of
 * `goods_receipt_lines_idempotency_uq` (02 §23.2).
 */
final class ReceiveLine
{
    /**
     * @param  list<string>  $serials  one per base unit, serial-tracked SKUs only
     */
    public function __construct(
        public readonly string $clientToken,
        public readonly ?int $purchaseOrderLineId,
        public readonly int $skuId,
        public readonly int $packId,
        public readonly int $packQty,
        public readonly ?string $batchCode = null,
        public readonly ?CarbonImmutable $expiresOn = null,
        public readonly array $serials = [],
        public readonly ?int $binId = null,
        public readonly ?int $unitCostE4 = null,
        public readonly bool $expiryWarningsConfirmed = false,
        public readonly ?int $actorUserId = null,
    ) {}
}
