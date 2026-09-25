<?php

namespace App\Domain\Billing\Events;

/**
 * An invoice or receipt was issued, after commit; carries ids only. For
 * side effects — the `invoice.issued` notification (06 §11), accounting
 * export. The PDF archive is queued by InvoiceService itself, not by a
 * listener, so it never depends on listener discovery.
 */
final readonly class InvoiceIssued
{
    public function __construct(
        public int $invoiceId,
        public int $orderId,
    ) {}
}
