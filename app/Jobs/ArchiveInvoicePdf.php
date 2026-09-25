<?php

namespace App\Jobs;

use App\Domain\Billing\InvoicePdfArchiver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders and archives an issued invoice's PDF off the request (07 P25:
 * 3 s, queued). Dispatched by InvoiceService after the invoice commits.
 * Safe to retry: the archiver writes the archive once.
 */
class ArchiveInvoicePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $invoiceId) {}

    public function handle(InvoicePdfArchiver $archiver): void
    {
        $archiver->archive($this->invoiceId);
    }
}
