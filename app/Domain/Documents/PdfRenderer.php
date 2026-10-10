<?php

namespace App\Domain\Documents;

/**
 * Renders a document to PDF bytes (05.17 §5: Puppeteer via
 * spatie/browsershot, BrowsershotPdfRenderer). Callers run off the
 * request, in a queued job (RenderDocument).
 *
 * The renderer receives only a trusted, explicit payload — never a URL,
 * HTML or a filesystem path from a customer.
 */
interface PdfRenderer
{
    /**
     * @return string|null the PDF bytes, or null when no renderer is
     *                     configured — which is a failure, never success
     *
     * @throws PdfRenderFailed when the renderer ran and failed
     */
    public function render(PdfDocument $document): ?string;
}
