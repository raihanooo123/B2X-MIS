<?php

namespace App\Domain\Documents;

/**
 * Renders a document to PDF bytes. The real implementation is the
 * Node/Puppeteer worker (01 §5.1, 07 P25), specified separately in
 * docs/12-pdf-worker.md. Callers run off the request, in a queued job.
 */
interface PdfRenderer
{
    /**
     * @return string|null the PDF bytes, or null when no renderer is
     *                     configured, in which case nothing is archived
     */
    public function render(PdfDocument $document): ?string;
}
