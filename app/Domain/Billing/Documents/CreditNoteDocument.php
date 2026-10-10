<?php

namespace App\Domain\Billing\Documents;

use App\Domain\Documents\PdfDocument;

/** An issued credit note, as the `credit_note` print template lays it out. */
final readonly class CreditNoteDocument implements PdfDocument
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    public function template(): string
    {
        return 'credit_note';
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
