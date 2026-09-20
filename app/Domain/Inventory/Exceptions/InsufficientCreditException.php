<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Doc 02 §11.1: credit is locked and checked FIRST, before any stock
 * lock is taken — a rejected order holds nothing.
 */
final class InsufficientCreditException extends RuntimeException
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $requiredCreditMinor,
        public readonly int $availableCreditMinor,
    ) {
        parent::__construct(
            "Company {$companyId} needs {$requiredCreditMinor} minor units of credit but only {$availableCreditMinor} is available — allocation aborted before any stock lock was taken."
        );
    }
}
