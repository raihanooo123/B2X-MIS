<?php

namespace App\Domain\Returns\Exceptions;

use RuntimeException;

/**
 * 05.4 §13.3: a cancellation request that cannot be accepted, with the
 * reason in words the customer can act on. `field` names the line when
 * one line is the problem (`lines.{line_no}`).
 */
final class CancellationRequestRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
