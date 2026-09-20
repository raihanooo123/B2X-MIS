<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * Doc 03 §4.6: the storefront never sends a non-active SKU to the
 * resolver — this guards callers that bypass that filter (admin, quotes,
 * tests), rather than resolving a price for something that cannot be sold.
 */
final class NotPurchasableException extends RuntimeException
{
    public function __construct(
        public readonly int $skuId,
        public readonly string $status,
    ) {
        parent::__construct("Sku {$skuId} has status '{$status}' and is not purchasable — only 'active' SKUs can be priced.");
    }
}
