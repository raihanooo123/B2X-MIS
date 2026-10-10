<?php

namespace App\Domain\Documents;

/**
 * No renderer configured (PDF_RENDERER=null, e.g. the test suite). Renders
 * nothing; DocumentRenders records the render as failed with
 * `renderer_unavailable`, so it can never pass for an archived PDF.
 */
final class NullPdfRenderer implements PdfRenderer
{
    public function render(PdfDocument $document): ?string
    {
        return null;
    }
}
