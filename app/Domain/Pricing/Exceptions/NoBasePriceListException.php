<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * Doc 03 §4.6: "SKU has no base list row" — a hard error. §3.2 states a
 * `base` scope list always exists for a sellable SKU, so reaching this
 * means SKU activation validation was bypassed or a base list/item was
 * deleted after the SKU went active. Never silently substitute a price
 * (§4.6's closing rule); surface it as a data-quality problem instead.
 */
final class NoBasePriceListException extends RuntimeException
{
    public function __construct(
        public readonly int $skuId,
        public readonly string $currency,
    ) {
        parent::__construct("Sku {$skuId} has no base-scope price_list_items row in any currency as of the requested time — it cannot be sold. This should have been blocked at SKU activation.");
    }
}
