<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class NoEligibleBatchException extends RuntimeException
{
    public function __construct(public readonly int $skuId)
    {
        parent::__construct('Stock exists, but no eligible batch is available for this SKU.');
    }
}
