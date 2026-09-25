<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Domain\Documents\PdfRenderer;
use App\Models\Attachment;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an issued invoice or receipt and archives the PDF as an
 * attachment (02 §21.1). The archive is the document as issued and is
 * written once: a repeat run finds it and does nothing. That is what makes
 * deriving line detail from `order_lines` safe (02 §14.5.2).
 *
 * `path` is a storage key under `invoices/`, never a public URL; the file
 * is served through the application (07 §6.3).
 */
final class InvoicePdfArchiver
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly InvoiceDocumentBuilder $builder = new InvoiceDocumentBuilder,
    ) {}

    public function archive(int $invoiceId): ?Attachment
    {
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            return null;
        }

        $existing = $invoice->archivedPdf()->first();
        if ($existing !== null) {
            return $existing;
        }

        $pdf = $this->renderer->render($this->builder->build($invoice));
        if ($pdf === null) {
            Log::warning('Invoice PDF not archived: no PDF renderer is configured (docs/12-pdf-worker.md).', [
                'invoice_id' => $invoiceId,
            ]);

            return null;
        }

        $disk = (string) config('filesystems.default');
        $path = "invoices/{$invoice->public_id}.pdf";
        Storage::disk($disk)->put($path, $pdf);

        return Attachment::query()->create([
            'attachable_type' => 'invoice',
            'attachable_id' => $invoice->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => "{$invoice->invoice_number}.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
            'is_customer_visible' => true,
            'uploaded_by_user_id' => null,
        ]);
    }
}
