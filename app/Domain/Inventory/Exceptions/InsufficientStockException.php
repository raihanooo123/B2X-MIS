<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\AllocationShortfall;
use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    /**
     * @param  list<AllocationShortfall>  $shortfalls
     */
    public function __construct(public readonly array $shortfalls)
    {
        parent::__construct('Insufficient stock for '.count($shortfalls).' identity/identities — allocation aborted, nothing was written.');
    }
}
