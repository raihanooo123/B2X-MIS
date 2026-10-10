<?php

namespace App\Domain\Documents;

/**
 * A document to render: a template name and the data it prints. The
 * template is a Blade print view in resources/views/documents/ (CLAUDE.md
 * permits Blade for printable documents) and receives this explicit array,
 * never a model. The data is complete: every figure printed is supplied,
 * already formatted, next to its integer (06 §3).
 */
interface PdfDocument
{
    /** Template the renderer lays the data out with, e.g. `invoice`. */
    public function template(): string;

    /** @return array<string, mixed> JSON-serialisable */
    public function toArray(): array;
}
