<?php

namespace App\Domain\Warehouse;

use App\Models\GoodsReceiptLine;

/**
 * What GoodsInService::receive() did. `replayed` is true when the line
 * already existed under this idempotency key: nothing was written, and
 * `line` is the original.
 */
final class ReceiptLineOutcome
{
    public function __construct(
        public readonly GoodsReceiptLine $line,
        public readonly bool $replayed,
    ) {}
}
