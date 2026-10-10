<?php

namespace App\Domain\Ordering\BulkEntry;

use RuntimeException;

/**
 * A whole paste or file refused before anything is staged (05.1 §14.1):
 * too many rows, no sku_code header, not UTF-8. The message is for the
 * buyer; the code is for the API envelope.
 */
final class EntryRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
