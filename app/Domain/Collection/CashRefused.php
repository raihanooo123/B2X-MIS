<?php

namespace App\Domain\Collection;

use RuntimeException;

/** 05.6 §7A.6: the counter cannot take, or void, this cash payment — with why. */
final class CashRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
