<?php

namespace App\Domain\Warehouse\Exceptions;

use RuntimeException;

/**
 * Internal to GoodsInService: the receipt line's idempotency key was
 * inserted by a concurrent transaction between the pre-read and the
 * insert. Thrown to roll back everything this attempt wrote; the service
 * then answers with the committed line. Never reaches a caller.
 */
final class ConcurrentReceiptLineException extends RuntimeException {}
