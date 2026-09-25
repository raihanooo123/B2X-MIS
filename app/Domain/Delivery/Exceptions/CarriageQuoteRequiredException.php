<?php

namespace App\Domain\Delivery\Exceptions;

use App\Domain\Delivery\DeliveryQuote;
use RuntimeException;

/**
 * Carriage cannot be charged for this order: it needs a manual quote, or
 * the destination is not served. Checkout refuses — nothing is placed,
 * no card is charged, carriage is never assumed to be £0.
 */
final class CarriageQuoteRequiredException extends RuntimeException
{
    public function __construct(public readonly DeliveryQuote $quote)
    {
        parent::__construct("Carriage cannot be rated for this order ({$quote->status}: {$quote->reason}).");
    }
}
