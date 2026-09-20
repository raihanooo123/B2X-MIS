<?php

namespace App\Domain\Reference\Exceptions;

use RuntimeException;

/**
 * Doc 02 §11.3: the counter is incremented "inside the same transaction
 * that creates the document, so a rolled-back order consumes no
 * number." If NumberSequenceService opened its own transaction and
 * committed independently, the number would be consumed even when the
 * caller's document creation later rolled back — precisely the gap this
 * table exists to prevent. This guards that contract instead of relying
 * on every caller to remember it.
 */
final class MustRunInsideTransactionException extends RuntimeException
{
    public function __construct(string $keyName)
    {
        parent::__construct(
            "NumberSequenceService::next('{$keyName}') must be called inside the caller's own document-creation transaction (Doc 02 §11.3) — calling it standalone would let a number be consumed even if the document is never actually created."
        );
    }
}
