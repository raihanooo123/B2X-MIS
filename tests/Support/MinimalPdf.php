<?php

namespace Tests\Support;

use App\Domain\Documents\PdfDocument;
use App\Domain\Documents\PdfRenderer;

/**
 * A valid one-page PDF and a renderer that returns it, so archive tests
 * exercise the real validation (05.17 §3) without Chromium. The real
 * renderer has its own opt-in test (RUN_PDF_RENDERER=1).
 */
final class MinimalPdf implements PdfRenderer
{
    public int $calls = 0;

    /** @var list<string> */
    public array $templates = [];

    public static function bytes(string $marker = ''): string
    {
        return "%PDF-1.4\n1 0 obj<</Type /Catalog /Pages 2 0 R>>endobj\n2 0 obj<</Type /Pages /Kids [3 0 R] /Count 1>>endobj\n"
            ."3 0 obj<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]>>endobj\n% {$marker}\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public function render(PdfDocument $document): ?string
    {
        $this->calls++;
        $this->templates[] = $document->template();

        return self::bytes($document->template());
    }
}
