<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * The lines derived from `order_lines` do not add up to the totals
 * snapshotted on the invoice. The document is not rendered: printing it
 * would put figures on paper that disagree with the ledger (03 §6).
 */
final class InvoiceTotalsMismatchException extends RuntimeException
{
    public function __construct(public readonly int $invoiceId, string $detail)
    {
        parent::__construct("Invoice {$invoiceId}: derived lines disagree with its totals — {$detail}.");
    }
}
