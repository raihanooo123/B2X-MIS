<?php

namespace App\Domain\Collection;

use App\Models\Invoice;
use App\Models\Payment;

/**
 * The cash payment taken (or, on a repeated click, the one already taken)
 * and the receipt or invoice issued for it — null when issuing failed and
 * was logged for `billing:issue-missing-invoices`.
 */
final readonly class CashRecorded
{
    public function __construct(
        public Payment $payment,
        public bool $replayed,
        public ?Invoice $document,
    ) {}
}
