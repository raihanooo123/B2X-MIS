<?php

namespace App\Domain\Ordering\BulkEntry;

use RuntimeException;

/**
 * A confirmation, list edit or preview refused by the rules of 05.1 §14:
 * 409 for a stale preview, basket, list version or expired import; 422 for
 * a selection that cannot be added. The message is for the buyer.
 */
final class ImportRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
