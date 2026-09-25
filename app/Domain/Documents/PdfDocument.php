<?php

namespace App\Domain\Documents;

/**
 * A document to render: a template name and the data it prints. The
 * renderer owns the layout, so no page markup lives in Laravel (CLAUDE.md:
 * no Blade views). The data must be complete: every figure the document
 * prints is supplied, already formatted, next to its integer (06 §3).
 */
interface PdfDocument
{
    /** Template the renderer lays the data out with, e.g. `invoice`. */
    public function template(): string;

    /** @return array<string, mixed> JSON-serialisable */
    public function toArray(): array;
}
