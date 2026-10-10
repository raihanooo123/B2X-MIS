<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Domain\Documents\DocumentRenders;
use App\Domain\Documents\PdfRenderer;
use App\Models\Attachment;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An issued invoice or receipt's archived PDF (02 §21.1, 05.17 §3).
 *
 * The payload is captured once, at issue (capture(), from InvoiceService's
 * after-commit hook), and every PDF is printed from it — so a reprint
 * matches the original exactly, whatever has changed since. An invoice
 * with no captured payload was issued before archiving existed: it is a
 * backfill blocker, never rebuilt from today's company or seller details
 * (05.17 §3). The archive is written once; a repeat run returns it.
 *
 * `path` is a storage key, never a public URL; the file is served through
 * the application (07 §6.3).
 */
final class InvoicePdfArchiver
{
    private readonly DocumentRenders $renders;

    public function __construct(
        PdfRenderer $renderer,
        private readonly InvoiceDocumentBuilder $builder = new InvoiceDocumentBuilder,
    ) {
        $this->renders = new DocumentRenders($renderer);
    }

    /**
     * Fix the invoice's printable payload now. Never throws: a document
     * that cannot be built (seller details missing, totals that do not add
     * up) is logged, and the invoice stays issued without a payload.
     */
    public static function capture(int $invoiceId): void
    {
        try {
            app(self::class)->captureNow($invoiceId);
        } catch (Throwable $e) {
            Log::error('Invoice document payload not captured.', ['invoice_id' => $invoiceId, 'error' => $e::class]);
        }
    }

    public function captureNow(int $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            return;
        }

        $this->renders->capture('invoice', $invoice->id, $invoice->company_id, $this->builder->build($invoice));
    }

    public function archive(int $invoiceId): ?Attachment
    {
        $existing = $this->renders->readyAttachment('invoice', $invoiceId);
        if ($existing !== null) {
            return $existing;
        }

        $render = $this->renders->latest('invoice', $invoiceId);
        if ($render === null) {
            Log::warning('Invoice PDF not archived: no payload was captured at issue (05.17 §3 backfill blocker).', [
                'invoice_id' => $invoiceId,
            ]);

            return null;
        }

        $this->renders->render($render->id);

        return $this->renders->readyAttachment('invoice', $invoiceId);
    }
}
