<?php

namespace App\Domain\Documents;

/**
 * What a rendered file must be before it is archived (05.17 §3): real,
 * non-empty `%PDF-` bytes ending in `%%EOF`, within the size and page
 * limits. Anything else is a classified failure, never an archive.
 */
final class PdfBytes
{
    /** @throws PdfRenderFailed */
    public static function validate(string $bytes): void
    {
        if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF')) {
            throw new PdfRenderFailed(PdfRenderFailed::NOT_A_PDF);
        }

        $maxBytes = (int) config('documents.limits.max_bytes');
        if (strlen($bytes) > $maxBytes) {
            throw new PdfRenderFailed(PdfRenderFailed::TOO_LARGE, strlen($bytes).' bytes');
        }

        $pages = self::pageCount($bytes);
        if ($pages < 1 || $pages > (int) config('documents.limits.max_pages')) {
            throw new PdfRenderFailed($pages < 1 ? PdfRenderFailed::NOT_A_PDF : PdfRenderFailed::TOO_LARGE, "{$pages} pages");
        }
    }

    /** Page objects in the file (`/Type /Page`, not `/Pages`). */
    public static function pageCount(string $bytes): int
    {
        return (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes);
    }
}
