<?php

namespace App\Domain\Ordering\Exceptions;

use RuntimeException;

/**
 * Doc 06 §9.3: "If the server's re-resolution disagrees, the response is
 * 409 price_changed with both figures — the customer confirms before
 * anything is committed (05.1 §10). A silently repriced order is never
 * acceptable." Thrown before any transaction is opened — a stale price
 * takes no lock of any kind, exactly like a failed credit check taking
 * no stock lock (05.2 §8.2).
 */
final class PriceChangedException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedTotalGrossMinor,
        public readonly int $actualTotalGrossMinor,
    ) {
        parent::__construct(
            "Checkout expected a total of {$expectedTotalGrossMinor} minor units but the server re-resolved {$actualTotalGrossMinor} — nothing was committed."
        );
    }
}
