<?php

namespace App\Domain\Credit;

use RuntimeException;

final class CreditRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
