<?php

namespace App\Domain\Documents;

/**
 * Stands in until the PDF worker exists (docs/12-pdf-worker.md). Renders
 * nothing, so issued documents are recorded and numbered but have no
 * archived PDF yet.
 */
final class NullPdfRenderer implements PdfRenderer
{
    public function render(PdfDocument $document): ?string
    {
        return null;
    }
}
