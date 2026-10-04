<?php

namespace App\Domain\Ordering\Exceptions;

use RuntimeException;

/**
 * 05.4 §13.2: why an order cannot be cancelled before dispatch. `reason`
 * is a stable code: `order_already_dispatched` (cancel by returning the
 * goods instead, §13.3), `already_cancelled`, `not_consumer_order` (a trade
 * order is amended through 05.10), or `not_cancellable` (any other state).
 */
final class OrderNotCancellableException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
