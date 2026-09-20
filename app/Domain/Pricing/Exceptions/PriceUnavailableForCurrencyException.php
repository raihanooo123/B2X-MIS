<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * Doc 03 §4.6: a base-scope price list exists for this SKU, but not in
 * the requested currency. Distinct from NoBasePriceListException, which
 * means no base list exists at all, in any currency.
 */
final class PriceUnavailableForCurrencyException extends RuntimeException
{
    public function __construct(
        public readonly int $skuId,
        public readonly string $currency,
    ) {
        parent::__construct("Sku {$skuId} has no price list in currency '{$currency}'.");
    }
}
