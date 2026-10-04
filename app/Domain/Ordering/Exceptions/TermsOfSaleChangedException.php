<?php

namespace App\Domain\Ordering\Exceptions;

use RuntimeException;

/**
 * 05.15 §6.1 step 4: a public buyer accepts the terms of sale in force.
 * The version they read is no longer the current one (or none is
 * published), so they must read and accept the current version first.
 * Thrown before any transaction is opened, like PriceChangedException.
 */
final class TermsOfSaleChangedException extends RuntimeException
{
    public function __construct(
        public readonly int $acceptedTermsVersionId,
        public readonly ?int $currentTermsVersionId,
    ) {
        parent::__construct(
            "Checkout accepted terms of sale version {$acceptedTermsVersionId}, but the version in force is ".($currentTermsVersionId ?? 'none').' — nothing was committed.'
        );
    }
}
