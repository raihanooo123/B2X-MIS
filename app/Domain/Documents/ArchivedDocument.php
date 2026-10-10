<?php

namespace App\Domain\Documents;

/**
 * A payload read back from document_renders, to render again exactly as it
 * was captured (05.17 §3: retry with the same payload).
 */
final readonly class ArchivedDocument implements PdfDocument
{
    /** @param array<string, mixed> $payload */
    public function __construct(private string $template, private array $payload) {}

    public function template(): string
    {
        return $this->template;
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
