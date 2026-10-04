<?php

namespace App\Domain\Returns\Exceptions;

use RuntimeException;

/**
 * A return cannot take this step in its current state (05.4 §13.5): proof
 * after the goods have arrived, a receipt for a return already received,
 * rejecting proof that is not there. `reason` is a stable code.
 */
final class ReturnActionRefusedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
